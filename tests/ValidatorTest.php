<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Exceptions\ValidationException;
use PHAPI\HTTP\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string, string, bool, string|null}>
     */
    public static function ruleProvider(): iterable
    {
        // --- required ---
        yield 'required present' => [['name' => 'Jo'], 'name', 'required', true, null];
        yield 'required missing' => [[], 'name', 'required', false, "Field 'name' is required"];
        yield 'required empty string' => [['name' => ''], 'name', 'required', false, "Field 'name' is required"];
        yield 'required null' => [['name' => null], 'name', 'required', false, "Field 'name' is required"];

        // --- optional ---
        yield 'optional missing skips' => [[], 'name', 'optional|email', true, null];
        yield 'optional empty skips' => [['name' => ''], 'name', 'optional|email', true, null];
        yield 'optional present validates' => [['name' => 'bad'], 'name', 'optional|email', false, "Field 'name' must be a valid email address"];
        yield 'optional present valid' => [['name' => 'a@b.com'], 'name', 'optional|email', true, null];

        // --- string ---
        yield 'string valid' => [['v' => 'hello'], 'v', 'required|string', true, null];
        yield 'string invalid int' => [['v' => 123], 'v', 'required|string', false, "Field 'v' must be a string"];
        yield 'string invalid bool' => [['v' => true], 'v', 'required|string', false, "Field 'v' must be a string"];

        // --- integer ---
        yield 'integer valid' => [['v' => 42], 'v', 'required|integer', true, null];
        yield 'integer valid string' => [['v' => '42'], 'v', 'required|integer', true, null];
        yield 'integer invalid float' => [['v' => '3.14'], 'v', 'required|integer', false, "Field 'v' must be an integer"];
        yield 'integer invalid string' => [['v' => 'abc'], 'v', 'required|integer', false, "Field 'v' must be an integer"];
        yield 'int alias' => [['v' => 5], 'v', 'required|int', true, null];

        // --- float/number ---
        yield 'float valid' => [['v' => 3.14], 'v', 'required|float', true, null];
        yield 'float valid string' => [['v' => '3.14'], 'v', 'required|float', true, null];
        yield 'float invalid' => [['v' => 'abc'], 'v', 'required|float', false, "Field 'v' must be a number"];
        yield 'number alias' => [['v' => 99], 'v', 'required|number', true, null];

        // --- boolean ---
        yield 'boolean true' => [['v' => true], 'v', 'required|boolean', true, null];
        yield 'boolean false' => [['v' => false], 'v', 'required|boolean', true, null];
        yield 'boolean string true' => [['v' => 'true'], 'v', 'required|boolean', true, null];
        yield 'boolean string 0' => [['v' => '0'], 'v', 'required|boolean', true, null];
        yield 'boolean string on' => [['v' => 'on'], 'v', 'required|bool', true, null];
        yield 'boolean invalid' => [['v' => 'banana'], 'v', 'required|boolean', false, "Field 'v' must be a boolean"];

        // --- array ---
        yield 'array valid' => [['v' => [1, 2]], 'v', 'required|array', true, null];
        yield 'array invalid' => [['v' => 'not-array'], 'v', 'required|array', false, "Field 'v' must be an array"];

        // --- email ---
        yield 'email valid' => [['e' => 'user@example.com'], 'e', 'required|email', true, null];
        yield 'email invalid' => [['e' => 'not-email'], 'e', 'required|email', false, "Field 'e' must be a valid email address"];

        // --- url ---
        yield 'url valid' => [['u' => 'https://example.com'], 'u', 'required|url', true, null];
        yield 'url invalid' => [['u' => 'not a url'], 'u', 'required|url', false, "Field 'u' must be a valid URL"];

        // --- min (string) ---
        yield 'min string pass' => [['v' => 'abc'], 'v', 'required|min:2', true, null];
        yield 'min string exact' => [['v' => 'ab'], 'v', 'required|min:2', true, null];
        yield 'min string fail' => [['v' => 'a'], 'v', 'required|min:2', false, "Field 'v' must be at least 2 characters"];

        // --- min (numeric) ---
        yield 'min numeric pass' => [['v' => 10], 'v', 'required|min:5', true, null];
        yield 'min numeric exact' => [['v' => 5], 'v', 'required|min:5', true, null];
        yield 'min numeric fail' => [['v' => 3], 'v', 'required|min:5', false, "Field 'v' must be at least 5"];

        // --- min (array) ---
        yield 'min array pass' => [['v' => [1, 2, 3]], 'v', 'required|min:2', true, null];
        yield 'min array fail' => [['v' => [1]], 'v', 'required|min:2', false, "Field 'v' must have at least 2 items"];

        // --- max (string) ---
        yield 'max string pass' => [['v' => 'ab'], 'v', 'required|max:3', true, null];
        yield 'max string exact' => [['v' => 'abc'], 'v', 'required|max:3', true, null];
        yield 'max string fail' => [['v' => 'abcd'], 'v', 'required|max:3', false, "Field 'v' must be at most 3 characters"];

        // --- max (numeric) ---
        yield 'max numeric pass' => [['v' => 3], 'v', 'required|max:5', true, null];
        yield 'max numeric fail' => [['v' => 10], 'v', 'required|max:5', false, "Field 'v' must be at most 5"];

        // --- max (array) ---
        yield 'max array pass' => [['v' => [1, 2]], 'v', 'required|max:3', true, null];
        yield 'max array fail' => [['v' => [1, 2, 3, 4]], 'v', 'required|max:3', false, "Field 'v' must have at most 3 items"];

        // --- length (string) ---
        yield 'length string exact' => [['v' => 'abc'], 'v', 'required|length:3', true, null];
        yield 'length string fail' => [['v' => 'ab'], 'v', 'required|length:3', false, "Field 'v' must be exactly 3 characters"];

        // --- length (array) ---
        yield 'length array exact' => [['v' => [1, 2]], 'v', 'required|length:2', true, null];
        yield 'length array fail' => [['v' => [1]], 'v', 'required|length:2', false, "Field 'v' must have exactly 2 items"];

        // --- in ---
        yield 'in valid' => [['v' => 'red'], 'v', 'required|in:red,green,blue', true, null];
        yield 'in invalid' => [['v' => 'purple'], 'v', 'required|in:red,green,blue', false, "Field 'v' must be one of: red, green, blue"];

        // --- regex ---
        yield 'regex match' => [['v' => 'abc123'], 'v', 'required|regex:/^[a-z0-9]+$/', true, null];
        yield 'regex no match' => [['v' => 'ABC!'], 'v', 'required|regex:/^[a-z0-9]+$/', false, "Field 'v' format is invalid"];
    }

    #[DataProvider('ruleProvider')]
    public function testValidationRule(array $data, string $field, string $rules, bool $expectValid, ?string $expectedError): void
    {
        $validator = new Validator($data, 'body');
        $validator->field($field, $rules);

        $this->assertSame($expectValid, $validator->isValid());

        if ($expectedError !== null) {
            $errors = $validator->errors();
            $this->assertArrayHasKey($field, $errors);
            $this->assertSame($expectedError, $errors[$field]);
        } else {
            $this->assertEmpty($validator->errors());
        }
    }

    public function testRulesMethodValidatesMultipleFields(): void
    {
        $validator = new Validator(['name' => 'Jo', 'age' => 'not-a-number'], 'body');
        $validator->rules([
            'name' => 'required|string',
            'age' => 'required|integer',
            'email' => 'required|email',
        ]);

        $this->assertFalse($validator->isValid());
        $errors = $validator->errors();
        $this->assertCount(2, $errors);
        $this->assertArrayHasKey('age', $errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayNotHasKey('name', $errors);
    }

    public function testValidateThrowsOnFailure(): void
    {
        $this->expectException(ValidationException::class);

        $validator = new Validator([], 'body');
        $validator->field('email', 'required');
        $validator->validate();
    }

    public function testValidatePassesSilentlyOnSuccess(): void
    {
        $validator = new Validator(['email' => 'a@b.com'], 'body');
        $validator->field('email', 'required|email');
        $validator->validate();

        $this->assertTrue($validator->isValid());
    }

    public function testDataTypeAccessor(): void
    {
        $this->assertSame('query', (new Validator([], 'query'))->dataType());
        $this->assertSame('body', (new Validator([], 'body'))->dataType());
        $this->assertSame('param', (new Validator([], 'param'))->dataType());
    }

    public function testFieldNotProvidedAndNotRequiredSkips(): void
    {
        $validator = new Validator([], 'body');
        $validator->field('optional_field', 'email');

        $this->assertTrue($validator->isValid());
    }

    public function testStopsOnFirstErrorPerField(): void
    {
        $validator = new Validator(['v' => 123], 'body');
        $validator->field('v', 'required|string|min:5');

        $errors = $validator->errors();
        $this->assertCount(1, $errors);
        $this->assertSame("Field 'v' must be a string", $errors['v']);
    }
}
