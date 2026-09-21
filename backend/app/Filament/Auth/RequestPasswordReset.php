<?php

namespace App\Filament\Auth;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Password;

class RequestPasswordReset extends \Filament\Auth\Pages\PasswordReset\RequestPasswordReset
{
    protected function getSentNotification(string $status): ?Notification
    {
        return Notification::make()
            ->title('Anfrage erhalten')
            ->body('Falls ein Konto mit dieser E-Mail-Adresse existiert, erhalten Sie einen Link zum Zurücksetzen Ihres Passworts. Bitte prüfen Sie auch Ihren Spam-Ordner.')
            ->success();
    }

    protected function getFailureNotification(string $status): ?Notification
    {
        // Use identical feedback and form state for known, unknown and recently requested addresses.
        // Token creation, delivery and rate limiting remain in Filament / Laravel.
        if (in_array($status, [Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            $this->form->fill();

            return $this->getSentNotification($status);
        }

        return parent::getFailureNotification($status);
    }
}
