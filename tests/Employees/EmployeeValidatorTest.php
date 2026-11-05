<?php

declare(strict_types=1);

namespace ReBuji\Tests\Employees;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Employees\EmployeeInput;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Employees\EmployeeValidator;
use ReBuji\Tests\Support\FrozenClock;

/**
 * @author shimomo
 */
final class EmployeeValidatorTest extends TestCase
{
    #[Test]
    public function acceptsACompleteEmployee(): void
    {
        [$validator] = $this->validator();

        $this->assertSame([], $validator->validate($this->input(
            emails: ['hanako@example.com', 'hanako.home@example.net'],
            cities: [['code' => '1310100', 'label' => '勤務地'], ['code' => '0110100', 'label' => '自宅']],
        )));
    }

    #[Test]
    public function requiresANameAnAddressAndACity(): void
    {
        [$validator] = $this->validator();

        $this->assertSame([
            '名前を入れてください。',
            'メールアドレスを 1 件以上入れてください（4 件まで）。',
            '市町村を 1 件以上選んでください（5 件まで）。',
        ], $validator->validate($this->input(name: '', emails: [], cities: [])));
    }

    #[Test]
    public function acceptsOldCarrierStyleAddresses(): void
    {
        // Consecutive dots and a dot before "@" break RFC 5322 but are still used on Japanese mobile carriers.
        [$validator] = $this->validator();

        $this->assertSame([], $validator->validate($this->input(emails: ['taro..yamada@docomo.ne.jp', 'hanako.@ezweb.ne.jp'])));
    }

    #[Test]
    public function refusesMalformedAddresses(): void
    {
        [$validator] = $this->validator();

        foreach (['taro', 'taro@localhost', 'a b@example.com', '山田@example.com', 'taro@example.com,jiro@example.com'] as $address) {
            $this->assertSame(['メールアドレスの形が正しくありません：' . $address], $validator->validate($this->input(emails: [$address])), $address);
        }
    }

    #[Test]
    public function refusesCodesTheEarthquakeInformationCannotUse(): void
    {
        [$validator] = $this->validator();
        $errors = $validator->validate($this->input(cities: [['code' => '0110000', 'label' => '']]));

        $this->assertCount(1, $errors);
        $this->assertStringStartsWith('市町村コード 0110000 は地震の情報で使えません。', $errors[0]);
        $this->assertStringContainsString('政令指定都市は区のコード', $errors[0]);
    }

    #[Test]
    public function refusesAMunicipalityRowWithoutACode(): void
    {
        [$validator] = $this->validator();

        $this->assertSame(['市町村が入っていません：自宅'], $validator->validate($this->input(cities: [
            ['code' => '1310100', 'label' => '勤務地'],
            ['code' => '', 'label' => '自宅'],
        ])));
        $this->assertSame(['都道府県を選んだ行に、市町村が入っていません。'], $validator->validate(EmployeeInput::from(
            '安否 花子',
            '',
            ['hanako@example.com'],
            [['code' => '1310100', 'label' => ''], ['code' => '', 'label' => '', 'prefecture' => '01']],
            '',
            true,
        )));
    }

    #[Test]
    public function refusesAnAddressAnotherEmployeeUses(): void
    {
        [$validator, $employees] = $this->validator();
        $first = $employees->create($this->input(name: '安否 太郎', emails: ['Taro@Example.com']));

        $this->assertSame(
            ['このメールアドレスは安否 太郎 さんが使っています：taro@example.com'],
            $validator->validate($this->input(emails: ['taro@example.com'])),
        );
        $this->assertSame([], $validator->validate($this->input(name: '安否 太郎', emails: ['Taro@Example.com']), $first));
    }

    #[Test]
    public function refusesTheSameAddressTwiceInOneEmployee(): void
    {
        [$validator] = $this->validator();

        $this->assertSame(
            ['同じメールアドレスが 2 回入っています：hanako@example.com'],
            $validator->validate($this->input(emails: ['Hanako@example.com', 'hanako@example.com'])),
        );
    }

    #[Test]
    public function refusesMoreThanFourAddressesOrFiveCities(): void
    {
        [$validator] = $this->validator();
        $emails = array_map(static fn(int $i): string => 'user' . $i . '@example.com', range(1, 5));
        $cities = array_fill(0, 6, ['code' => '1310100', 'label' => '']);

        $this->assertSame(
            ['メールアドレスを 1 件以上入れてください（4 件まで）。', '市町村を 1 件以上選んでください（5 件まで）。'],
            $validator->validate($this->input(emails: $emails, cities: $cities)),
        );
    }

    #[Test]
    public function refusesTooLongValues(): void
    {
        [$validator] = $this->validator();

        $this->assertSame([
            '名前は 100 文字までです。',
            '所属は 100 文字までです。',
            '呼び名は 20 文字までです：' . str_repeat('宅', 21),
            'メモは 2,000 文字までです。',
        ], $validator->validate($this->input(
            name: str_repeat('名', 101),
            department: str_repeat('部', 101),
            cities: [['code' => '1310100', 'label' => str_repeat('宅', 21)]],
            memo: str_repeat('メ', 2001),
        )));
        $this->assertSame([], $validator->validate($this->input(name: str_repeat('名', 100), memo: str_repeat('メ', 2000))));
    }

    /**
     * @return array{EmployeeValidator, EmployeeRepository}
     */
    private function validator(): array
    {
        $db = Database::sqliteInMemory();
        $clock = new FrozenClock();
        (new Migrator($db, Migrations::all(), $clock))->migrate();
        $employees = new EmployeeRepository($db, $clock);

        return [new EmployeeValidator($employees), $employees];
    }

    /**
     * @param list<string> $emails
     * @param list<array{code: string, label: string}> $cities
     */
    private function input(
        string $name = '安否 花子',
        string $department = '総務部',
        array $emails = ['hanako@example.com'],
        array $cities = [['code' => '1310100', 'label' => '勤務地']],
        string $memo = '',
    ): EmployeeInput {
        return EmployeeInput::from($name, $department, $emails, $cities, $memo, true);
    }
}
