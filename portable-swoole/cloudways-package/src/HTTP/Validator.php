<?php

namespace PHAPI\HTTP;

use PHAPI\Exceptions\ValidationException;

/**
 * Simple, fluent validation API
 */
class Validator
{
    private array $data;
    private array $errors = [];
    private string $dataType; // 'body', 'query', 'param'

    public function __construct(array $data, string $dataType = 'body')
    {
        $this->data = $data;
        $this->dataType = $dataType;
    }

    /**
     * Validate field - returns self for chaining
     */
    public function field(string $field, string $rules): self
    {
        $rules = explode('|', $rules);
        $value = $this->data[$field] ?? null;
        $isRequired = in_array('required', $rules);
        $isOptional = in_array('optional', $rules);

        // Check if field is required
        if ($isRequired && ($value === null || $value === '')) {
            $this->errors[$field] = "Field '{$field}' is required";
            return $this;
        }

        // Skip validation if optional and not provided
        if ($isOptional && ($value === null || $value === '')) {
            return $this;
        }

        // If field is not provided and not required/optional, skip
        if (!$isRequired && !$isOptional && !isset($this->data[$field])) {
            return $this;
        }

        // Apply validation rules
        foreach ($rules as $rule) {
            if ($rule === 'required' || $rule === 'optional') {
                continue; // Already handled
            }

            $ruleParts = explode(':', $rule, 2);
            $ruleName = $ruleParts[0];
            $ruleValue = $ruleParts[1] ?? null;

            if (!$this->validateRule($field, $value, $ruleName, $ruleValue)) {
                break; // Stop on first error for this field
            }
        }

        return $this;
    }

    /**
     * Validate multiple fields at once
     */
    public function rules(array $rules): self
    {
        foreach ($rules as $field => $fieldRules) {
            $this->field($field, $fieldRules);
        }
        return $this;
    }

    /**
     * Check if validation passed
     */
    public function isValid(): bool
    {
        return empty($this->errors);
    }

    /**
     * Get validation errors
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Validate and throw exception if invalid
     */
    public function validate(): void
    {
        if (!$this->isValid()) {
            throw new ValidationException('Validation failed', $this->errors);
        }
    }

    /**
     * Apply a single validation rule
     */
    private function validateRule(string $field, $value, string $ruleName, ?string $ruleValue): bool
    {
        switch ($ruleName) {
            case 'string':
                if (!is_string($value)) {
                    $this->errors[$field] = "Field '{$field}' must be a string";
                    return false;
                }
                break;

            case 'integer':
            case 'int':
                if (!is_numeric($value) || (int)$value != $value) {
                    $this->errors[$field] = "Field '{$field}' must be an integer";
                    return false;
                }
                break;

            case 'float':
            case 'number':
                if (!is_numeric($value)) {
                    $this->errors[$field] = "Field '{$field}' must be a number";
                    return false;
                }
                break;

            case 'boolean':
            case 'bool':
                if (!is_bool($value) && !in_array($value, ['0', '1', 'true', 'false', 'on', 'off'])) {
                    $this->errors[$field] = "Field '{$field}' must be a boolean";
                    return false;
                }
                break;

            case 'array':
                if (!is_array($value)) {
                    $this->errors[$field] = "Field '{$field}' must be an array";
                    return false;
                }
                break;

            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->errors[$field] = "Field '{$field}' must be a valid email address";
                    return false;
                }
                break;

            case 'url':
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    $this->errors[$field] = "Field '{$field}' must be a valid URL";
                    return false;
                }
                break;

            case 'min':
                $min = (int)$ruleValue;
                if (is_numeric($value)) {
                    if ((float)$value < $min) {
                        $this->errors[$field] = "Field '{$field}' must be at least {$min}";
                        return false;
                    }
                } elseif (is_string($value)) {
                    if (strlen($value) < $min) {
                        $this->errors[$field] = "Field '{$field}' must be at least {$min} characters";
                        return false;
                    }
                } elseif (is_array($value)) {
                    if (count($value) < $min) {
                        $this->errors[$field] = "Field '{$field}' must have at least {$min} items";
                        return false;
                    }
                }
                break;

            case 'max':
                $max = (int)$ruleValue;
                if (is_numeric($value)) {
                    if ((float)$value > $max) {
                        $this->errors[$field] = "Field '{$field}' must be at most {$max}";
                        return false;
                    }
                } elseif (is_string($value)) {
                    if (strlen($value) > $max) {
                        $this->errors[$field] = "Field '{$field}' must be at most {$max} characters";
                        return false;
                    }
                } elseif (is_array($value)) {
                    if (count($value) > $max) {
                        $this->errors[$field] = "Field '{$field}' must have at most {$max} items";
                        return false;
                    }
                }
                break;

            case 'length':
                $length = (int)$ruleValue;
                if (is_string($value) && strlen($value) !== $length) {
                    $this->errors[$field] = "Field '{$field}' must be exactly {$length} characters";
                    return false;
                } elseif (is_array($value) && count($value) !== $length) {
                    $this->errors[$field] = "Field '{$field}' must have exactly {$length} items";
                    return false;
                }
                break;

            case 'in':
                $allowed = explode(',', $ruleValue ?? '');
                if (!in_array($value, $allowed)) {
                    $this->errors[$field] = "Field '{$field}' must be one of: " . implode(', ', $allowed);
                    return false;
                }
                break;

            case 'regex':
                if (!preg_match($ruleValue ?? '', (string)$value)) {
                    $this->errors[$field] = "Field '{$field}' format is invalid";
                    return false;
                }
                break;
        }

        return true;
    }
}

