<?php
declare(strict_types=1);

namespace HexaLite\Http\DTO\Attributes;

# Rechaza '<', '>' y caracteres de control en el valor. Defensa en profundidad
# para campos de identidad (nombres): la carga se corta en la ENTRADA, no solo
# se escapa en la salida. Mapea a la regla `no_html` del RuleEngine.
#[\Attribute(\Attribute::TARGET_PROPERTY)]
readonly class NoHtml implements ValidationAttribute
{
    public function __construct(public ?string $message = null) {}

    public function toRuleString(): string { return 'no_html'; }
    public function getRuleName(): string { return 'no_html'; }
    public function getMessage(): ?string { return $this->message; }
}
