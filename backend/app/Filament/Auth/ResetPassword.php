<?php

namespace App\Filament\Auth;

use Filament\Schemas\Components\Component;

class ResetPassword extends \Filament\Auth\Pages\PasswordReset\ResetPassword
{
    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->minLength(12);
    }
}
