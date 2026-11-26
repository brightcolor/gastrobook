<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Jede Meldung, die eine Mail ankündigt, nennt den Spam-Ordner (Vorgabe von
 * Mathias vom 26.11.2026). Der Wächter sucht in Ansichten und Controllern
 * nach Sätzen, die eine Mail ankündigen, und verlangt in derselben Datei den
 * Hinweis aus App\Support\MailNotice (direkt oder als <x-spam-hint>).
 *
 * Mails selbst (resources/views/emails, mail) und die Werbeseite gehören
 * nicht dazu: Dort kündigt niemand eine Mail an.
 */
class MailNoticeGuardTest extends TestCase
{
    /** Sätze, die eine Mail an den Leser ankündigen. */
    private const ANNOUNCEMENTS = [
        '/(Mail|E-Mail|Link|Anmeldelink|Reset-Link|Bestätigungslink)[^.\n]{0,80}(geschickt|gesendet|verschickt)/u',
        '/(erhältst du|erhalten Sie|bekommst du|bekommen Sie|hast du|haben Sie)[^.\n]{0,60}(E-Mail|Mail\b|Postfach)/u',
        '/schreiben (dir|Ihnen)|schicken (dir|Ihnen)|Wir haben (dir|Ihnen)[^.\n]{0,40}geschrieben/u',
        '/melden uns per E-Mail/u',
    ];

    /** Verzeichnisse, die nicht zur Oberfläche gehören. */
    private const SKIP = ['/resources/views/emails/', '/resources/views/mail/', '/resources/views/vendor/', '/resources/views/marketing/'];

    public function test_every_mail_announcement_names_the_spam_folder(): void
    {
        $root = dirname(__DIR__, 2);
        $missing = [];

        foreach (['resources/views', 'app/Http'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if (! str_ends_with($path, '.php')) {
                    continue;
                }
                foreach (self::SKIP as $skip) {
                    if (str_contains($path, $skip)) {
                        continue 2;
                    }
                }

                $content = (string) file_get_contents($path);
                if (str_contains($content, 'MailNotice::') || str_contains($content, 'x-spam-hint')) {
                    continue;
                }

                foreach (explode("\n", $content) as $number => $line) {
                    $trimmed = ltrim($line);
                    if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '{{--')) {
                        continue;
                    }
                    // Eine Aussage über Mails, die es nicht gibt („keine E-Mails
                    // verschickt“), kündigt keine an.
                    if (preg_match('/\b(keine|nicht)\b/u', $line) === 1) {
                        continue;
                    }
                    foreach (self::ANNOUNCEMENTS as $pattern) {
                        if (preg_match($pattern, $line) === 1) {
                            $missing[] = substr($path, strlen($root) + 1).':'.($number + 1).'  '.trim($line);
                            break;
                        }
                    }
                }
            }
        }

        $this->assertSame([], $missing, "Diese Meldungen kündigen eine Mail an, nennen aber den Spam-Ordner nicht (App\\Support\\MailNotice oder <x-spam-hint>):\n".implode("\n", $missing));
    }
}
