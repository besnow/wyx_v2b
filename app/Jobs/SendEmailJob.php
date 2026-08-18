<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use App\Models\MailLog;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $params;

    public $tries = 1;
    public $timeout = 60;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($params, $queue = 'send_email')
    {
        $this->onQueue($queue);
        $this->params = $params;

        if ($queue === 'send_email_mass') {
            $this->applyMassEmailDelay();
        }
    }

    private function applyMassEmailDelay()
    {
        $delaySeconds = 31;
        $cacheKey = 'SEND_EMAIL_MASS_NEXT_AVAILABLE_AT';
        $now = time();
        $nextAvailableAt = (int) Cache::get($cacheKey, $now);
        $availableAt = max($now, $nextAvailableAt);

        Cache::put($cacheKey, $availableAt + $delaySeconds, 86400 * 2);
        $this->delay(now()->addSeconds($availableAt - $now));
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (config('v2board.email_host')) {
            Config::set('mail.host', config('v2board.email_host', env('mail.host')));
            Config::set('mail.port', config('v2board.email_port', env('mail.port')));
            Config::set('mail.encryption', config('v2board.email_encryption', env('mail.encryption')));
            Config::set('mail.username', config('v2board.email_username', env('mail.username')));
            Config::set('mail.password', config('v2board.email_password', env('mail.password')));
            Config::set('mail.from.address', config('v2board.email_from_address', env('mail.from.address')));
            Config::set('mail.from.name', config('v2board.app_name', 'V2Board'));
        }
        $params = $this->params;
        $email = $params['email'];
        $subject = $params['subject'];
        $params['template_name'] = 'mail.' . config('v2board.email_template', 'default') . '.' . $params['template_name'];
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $this->send($params, $email, $subject);
                unset($error);
                break;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                if ($attempt === 2 || !$this->isTemporarySmtpError($e)) {
                    break;
                }

                sleep(2);
            }
        }

        $log = [
            'email' => $params['email'],
            'subject' => $params['subject'],
            'template_name' => $params['template_name'],
            'error' => isset($error) ? $error : NULL
        ];

        MailLog::create($log);
        $log['config'] = config('mail');
        return $log;
    }

    private function send($params, $email, $subject)
    {
        Mail::purge();
        $transport = null;

        try {
            $mailer = Mail::mailer();
            $transport = $mailer->getSwiftMailer()->getTransport();
            $mailer->send(
                $params['template_name'],
                $params['template_value'],
                function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                }
            );
        } finally {
            if ($transport) {
                try {
                    $transport->stop();
                } catch (\Throwable $cleanupException) {
                    // Ignore transport cleanup failures without changing the send result.
                }
            }

            Mail::purge();
        }
    }

    private function isTemporarySmtpError(\Throwable $exception)
    {
        $message = $exception->getMessage();

        if (preg_match('/(?:\bSMTP\b[^\r\n]*|got (?:an empty )?(?:response|code)[^\r\n]*?)\b4\d{2}\b/i', $message)) {
            return true;
        }

        return preg_match(
            '/broken pipe|connection reset by peer|connection (?:timed out|timeout|refused)|'
            . 'timed? out|read timeout|end of file|\bEOF\b|empty response|no response|'
            . 'unable to connect|failed to connect|could not be established|cannot connect/i',
            $message
        ) === 1;
    }
}
