<?php
declare(strict_types=1);

namespace HexaLite\Validation;

/**
 * Validador orientado a $request->validate(). Conserva su API pública (reglas en
 * formato string "a|b:c" por campo y recolección de TODOS los errores por campo),
 * pero delega la semántica de cada regla en el motor único {@see RuleEngine},
 * eliminando la duplicación que antes divergía respecto a los DTOs.
 */
class Validator
{
    private array $errors = [];
    private array $data;

    /** @var array<string, array<string, string>> $customMessages [field => [ruleName => message]] */
    private array $customMessages = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @param array<string, string|array<int, string>> $rules          ['campo' => 'regla1|regla2:param']
     * @param array<string, array<string, string>>     $customMessages ['campo' => ['nombreRegla' => 'mensaje']]
     * @return array<string, array<int, string>>                       errores por campo (vacío si todo válido)
     */
    public function validate(array $rules, array $customMessages = []): array
    {
        $this->customMessages = $customMessages;
        $this->errors         = [];

        foreach ($rules as $field => $ruleDef) {
            $parsed = RuleEngine::parse($ruleDef);
            $value  = $this->data[$field] ?? null;

            // Si el valor es null y la regla `nullable` está presente, se saltan las demás.
            if ($value === null && RuleEngine::isNullable($parsed)) {
                continue;
            }

            foreach ($parsed as $r) {
                $this->applyParsed($field, $value, $r['name'], $r['param']);
            }
        }

        return $this->errors;
    }

    /**
     * Aplica una regla ya parseada y acumula el error si falla (recolecta todos
     * los errores del campo, sin fail-fast — comportamiento histórico del Validator).
     */
    private function applyParsed(string $field, mixed $value, string $name, ?string $param): void
    {
        if ($name === 'custom') {
            if (!RuleEngine::runCustom($param, $value, $this->data)) {
                $key    = "custom:{$param}";
                $custom = $this->customMessages[$field][$key] ?? null;
                $this->errors[$field][] = $custom ?? RuleEngine::customDefaultMessage((string) $param, $field);
            }
            return;
        }

        if (!RuleEngine::passes($name, $value, $param, $this->data, $field)) {
            $custom = $this->customMessages[$field][$name] ?? null;
            $this->errors[$field][] = $custom ?? RuleEngine::message($name, $field, $param, $value);
        }
    }
}
