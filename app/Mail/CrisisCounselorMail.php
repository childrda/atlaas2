<?php

namespace App\Mail;

use App\Models\SafetyAlert;
use App\Models\StudentModeSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CrisisCounselorMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SafetyAlert $alert,
        public StudentModeSettings $settings,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[ATLAAS] Crisis alert — '.$this->alert->student->name,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.crisis-counselor');
    }
}
