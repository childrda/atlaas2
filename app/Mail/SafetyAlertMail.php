<?php

namespace App\Mail;

use App\Models\SafetyAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SafetyAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SafetyAlert $alert) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[ATLAAS] Safety Alert — '.$this->alert->student->name,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.safety-alert');
    }
}
