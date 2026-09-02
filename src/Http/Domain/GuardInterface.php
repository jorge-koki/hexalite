<?php
declare(strict_types=1);

namespace HexaLite\Http\Domain;

use HexaLite\Http\Request;
use HexaLite\Http\Response;

interface GuardInterface
{
    public function canActivate(Request $request): bool | Response;
}
