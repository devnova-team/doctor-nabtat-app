<?php

namespace App\Services;

class EgyptianNationalIdParser
{
    /** @var array<string> */
    private const GOVERNORATE_CODES = [
        '01', '02', '03', '04', '11', '12', '13', '14', '15', '16', '17', '18',
        '19', '21', '22', '23', '24', '25', '26', '27', '28', '29', '31', '32',
        '33', '34', '35', '88',
    ];

    /**
     * @return array{date_of_birth: string, gender: string, governorate_code: string}|null
     */
    public function parse(string $nationalId): ?array
    {
        if (! preg_match('/^\d{14}$/', $nationalId)) {
            return null;
        }

        $centuryDigit = $nationalId[0];
        if (! in_array($centuryDigit, ['2', '3'], true)) {
            return null;
        }

        $year = (int) substr($nationalId, 1, 2);
        $month = (int) substr($nationalId, 3, 2);
        $day = (int) substr($nationalId, 5, 2);
        $fullYear = ($centuryDigit === '2' ? 1900 : 2000) + $year;

        if (! checkdate($month, $day, $fullYear)) {
            return null;
        }

        $birthDate = sprintf('%04d-%02d-%02d', $fullYear, $month, $day);
        if ($birthDate > now()->toDateString()) {
            return null;
        }

        $governorateCode = substr($nationalId, 7, 2);
        if (! in_array($governorateCode, self::GOVERNORATE_CODES, true)) {
            return null;
        }

        return [
            'date_of_birth' => $birthDate,
            'gender' => ((int) $nationalId[12] % 2) === 1 ? 'male' : 'female',
            'governorate_code' => $governorateCode,
        ];
    }
}
