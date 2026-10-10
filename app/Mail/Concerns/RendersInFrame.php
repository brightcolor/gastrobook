<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Mail\Layout\Block;
use App\Mail\Layout\MailFrame;
use App\Mail\Layout\MailFrameFactory;
use App\Mail\Layout\TextBlocks;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Headers;

/**
 * Text einer Mail im gemeinsamen Rahmen: HTML aus den erkannten Bausteinen,
 * Textteil aus dem normalisierten Text plus Fußbereich.
 */
trait RendersInFrame
{
    protected function framedContent(string $text, MailFrame $frame): Content
    {
        $plain = TextBlocks::normalize($text);

        if (! $frame->structured) {
            // Fremder Text (Gast-Antwort, Kontaktformular): nur Absätze, nie Knöpfe.
            $blocks = $plain === '' ? [] : array_map(
                static fn (string $paragraph) => Block::paragraph($paragraph),
                explode("\n\n", $plain),
            );
        } else {
            $blocks = TextBlocks::fromConfig(
                $frame->unsubscribeUrl,
                $frame->isSwayy() ? MailFrameFactory::buttonHosts() : [],
            )->parse($plain);
        }

        return new Content(
            html: 'mail.layout.html',
            text: 'mail.layout.text',
            // Eigene Namen: Laravel reicht öffentliche Eigenschaften eines
            // Mailables als View-Daten durch und überschriebe sonst etwa „frame“.
            with: [
                'mailFrame' => $frame,
                'mailTheme' => $frame->theme(),
                'mailBlocks' => $blocks,
                'mailPlain' => $plain,
            ],
        );
    }

    protected function framedHeaders(MailFrame $frame): Headers
    {
        if ($frame->unsubscribeUrl === null) {
            return new Headers;
        }

        return new Headers(text: array_filter([
            'List-Id' => $frame->listId,
            'List-Unsubscribe' => '<'.$frame->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]));
    }
}
