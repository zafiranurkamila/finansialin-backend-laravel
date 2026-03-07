c:\Users\zafir\Downloads\Semester 5\ippl\project\finansialin-backend-laravel>git commit -m "backend laravel"
On branch master

Initial commit

Untracked files:
  (use "git add <file>..." to include in what will be committed)      
        .editorconfig
        .env.example
        .gitattributes
        .gitignore
        README.md
        app/
        artisan
        bootstrap/
        composer.json
        composer.lock
        config/
        database/
        package.json
        phpunit.xml
        public/
        resources/
        routes/
        storage/
        tests/
        vite.config.js

nothing added to commit but untracked files present (use "git add" to 
track)

c:\Users\zafir\Downloads\Semester 5\ippl\project\finansialin-backend-laravel><?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $email,
        public string $verificationUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verify your Finansialin account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify-email',
            with: [
                'email' => $this->email,
                'verificationUrl' => $this->verificationUrl,
            ],
        );
    }
}
