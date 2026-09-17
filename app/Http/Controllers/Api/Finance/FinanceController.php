<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\ApiController;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

abstract class FinanceController extends ApiController
{
    /** Tutar: 1500 | 1500.5 | 1500,50 — float'a hiç çevrilmez, servis Money::of ile okur. */
    protected const MONEY = 'regex:/^(\d{1,12}([.,]\d{1,2})?|\d{1,3}(\.\d{3}){1,3}(,\d{1,2})?)$/';

    protected const MONEY_SIGNED = 'regex:/^-?(\d{1,12}([.,]\d{1,2})?|\d{1,3}(\.\d{3}){1,3}(,\d{1,2})?)$/';

    /**
     * Türkçe alan adlarıyla doğrulama (FinanceValidation). Uca özel iletiler/adlar önceliklidir.
     *
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $messages
     * @param array<string, string> $attributes
     * @return array<string, mixed>
     */
    protected function validateTr(Request $request, array $rules, array $messages = [], array $attributes = []): array
    {
        return $request->validate($rules, $messages + FinanceValidation::messages(), $attributes + FinanceValidation::attributes());
    }

    protected function branchId(): int
    {
        return app(BranchContext::class)->require();
    }

    /** Tutar: kuruş hassasiyetinde string → Excel hücresinde sayı (yalnız gösterim). */
    protected function cell(mixed $amount): float
    {
        return (float) bcadd((string) ($amount ?? '0'), '0', 2);
    }

    /**
     * @param list<string> $header
     * @param callable(callable(array): void): void $rows
     */
    protected function xlsx(string $filename, array $header, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $writer = new Writer();
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues($header));
            $rows(fn (array $values) => $writer->addRow(Row::fromValues(array_values($values))));
            $writer->close();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
