<?php

namespace App\Support\Cart\Concerns;

/**
 * Shared array{success, status, message, data, errors?} result shape every
 * Cart Action returns from inside its DB::transaction() closure. Returning
 * a value (rather than throwing) for expected rejection reasons - not found,
 * validation failure, UNAVAILABLE pricing - means the transaction commits
 * normally with nothing written, since every Action performs all writes
 * only after these checks pass. Genuine failures (constraint violations)
 * still throw and roll the transaction back.
 */
trait BuildsCartResult
{
    /**
     * @return array{success: bool, status: int, message: string, data: null}
     */
    private function notFound(string $message): array
    {
        return ['success' => false, 'status' => 404, 'message' => $message, 'data' => null];
    }

    /**
     * @param  array<int, string>  $errors
     * @return array{success: bool, status: int, message: string, data: null, errors: array<int, string>}
     */
    private function unprocessable(string $message, array $errors = []): array
    {
        return ['success' => false, 'status' => 422, 'message' => $message, 'data' => null, 'errors' => $errors];
    }

    /**
     * @return array{success: bool, status: int, message: string, data: null}
     */
    private function conflict(string $message): array
    {
        return ['success' => false, 'status' => 409, 'message' => $message, 'data' => null];
    }

    /**
     * @return array{success: bool, status: int, message: string, data: array<string, mixed>}
     */
    private function ok(int $status, string $message, array $data): array
    {
        return ['success' => true, 'status' => $status, 'message' => $message, 'data' => $data];
    }
}
