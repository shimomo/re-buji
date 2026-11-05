<?php

declare(strict_types=1);

namespace ReBuji\Employees;

/**
 * Checks an employee before it is saved, from the form or from CSV (design 3.1).
 *
 * @author shimomo
 */
final class EmployeeValidator
{
    public const int MAX_EMAILS = 4;

    public const int MAX_CITIES = 5;

    /**
     * Looser than RFC 5322 on purpose: Japanese mobile carriers still have addresses with consecutive dots or a dot
     * before "@" (taro..yamada@docomo.ne.jp), which filter_var() refuses. ASCII only; one "@"; a domain with a dot.
     */
    private const string ADDRESS_PATTERN = '/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~.\-]{1,64}@(?:[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/';

    public function __construct(private readonly EmployeeRepository $employees)
    {
        //
    }

    public static function isAddress(string $address): bool
    {
        return strlen($address) <= 254 && preg_match(self::ADDRESS_PATTERN, $address) === 1;
    }

    /**
     * Everything that does not need the database: counts, lengths, the shape of addresses, municipality codes.
     *
     * @return list<string>
     */
    public function validateFields(EmployeeInput $input): array
    {
        $errors = [];

        if ($input->name === '') {
            $errors[] = '名前を入れてください。';
        } elseif (mb_strlen($input->name) > 100) {
            $errors[] = '名前は 100 文字までです。';
        }

        if (mb_strlen($input->department) > 100) {
            $errors[] = '所属は 100 文字までです。';
        }

        if ($input->emails === [] || count($input->emails) > self::MAX_EMAILS) {
            $errors[] = 'メールアドレスを 1 件以上入れてください（' . self::MAX_EMAILS . ' 件まで）。';
        }

        $seen = [];

        foreach ($input->emails as $address) {
            $key = EmployeeRepository::addressKey($address);

            if (!self::isAddress($address)) {
                $errors[] = 'メールアドレスの形が正しくありません：' . $address;
            } elseif (isset($seen[$key])) {
                $errors[] = '同じメールアドレスが 2 回入っています：' . $address;
            }

            $seen[$key] = true;
        }

        if ($input->cities === [] || count($input->cities) > self::MAX_CITIES) {
            $errors[] = '市町村を 1 件以上選んでください（' . self::MAX_CITIES . ' 件まで）。';
        }

        foreach ($input->cities as $city) {
            if ($city->code === '') {
                $errors[] = $city->label === '' ? '都道府県を選んだ行に、市町村が入っていません。' : '市町村が入っていません：' . $city->label;
            } elseif (Cities::find($city->code) === null) {
                $errors[] = '市町村コード ' . $city->code . ' は地震の情報で使えません。政令指定都市は区のコードを、合併や再編で変わった市町村は今のコードを使ってください。';
            }

            if (mb_strlen($city->label) > 20) {
                $errors[] = '呼び名は 20 文字までです：' . $city->label;
            }
        }

        if (mb_strlen($input->memo) > 2000) {
            $errors[] = 'メモは 2,000 文字までです。';
        }

        return $errors;
    }

    /**
     * validateFields(), and no address may belong to another employee (replies are matched by sender, design 5.2).
     *
     * @param ?int $employeeId the employee being edited, whose own addresses are fine
     *
     * @return list<string>
     */
    public function validate(EmployeeInput $input, ?int $employeeId = null): array
    {
        $errors = $this->validateFields($input);

        foreach ($input->emails as $address) {
            $owner = self::isAddress($address) ? $this->employees->addressOwner($address) : null;

            if ($owner !== null && $owner['id'] !== $employeeId) {
                $errors[] = 'このメールアドレスは' . $owner['name'] . ' さんが使っています：' . $address;
            }
        }

        return $errors;
    }
}
