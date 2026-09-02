<?php
declare(strict_types=1);

namespace HexaLite\Providers;

interface ProviderInterface
{
    public function register(): void;
    public function boot(): void;
}
