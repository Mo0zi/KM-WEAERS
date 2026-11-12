<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\RfqRepository;
use App\Services\RfqMailer;

final class PageController
{
    public function __construct(
        private RfqRepository $repository,
        private RfqMailer $mailer,
    ) {
    }

    public function show(string $page): void
    {
        $csrfToken = $this->csrfToken();
        $successMessage = $_SESSION['flash_success'] ?? '';
        $errorMessage = $_SESSION['flash_error'] ?? '';
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        require __DIR__ . '/../Views/pages/' . $page . '.php';
    }

    public function submitRfq(): void
    {
        if (!hash_equals($this->csrfToken(), (string) ($_POST['csrf_token'] ?? ''))) {
            $this->redirectWithError('Your form session expired. Please try again.');
        }

        if (trim((string) ($_POST['website'] ?? '')) !== '') {
            $this->redirectWithError('Your submission could not be accepted.');
        }
        if (!$this->withinRateLimit()) {
            $this->redirectWithError('Too many requests. Please wait an hour before trying again.');
        }

        $category = (string) ($_POST['category'] ?? '');
        $categories = ['streetwear', 'fashionwear', 'casualwear', 'sportswear', 'workwear'];
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 10, 'max_range' => 100000]]);
        $rfq = [
            'name' => $this->cleanField($_POST['name'] ?? '', 100),
            'email' => $this->cleanField($_POST['email'] ?? '', 254),
            'phone' => $this->cleanField($_POST['phone'] ?? '', 40),
            'category' => in_array($category, $categories, true) ? $category : '',
            'quantity' => $quantity ?: null,
            'details' => $this->cleanField($_POST['details'] ?? '', 3000),
        ];

        if ($rfq['name'] === '' || !filter_var($rfq['email'], FILTER_VALIDATE_EMAIL) || $rfq['details'] === '' || $rfq['category'] === '' || $rfq['quantity'] === null) {
            $this->redirectWithError('Please complete all required RFQ fields with a valid quantity of at least 10 pieces.');
        }

        try {
            $this->repository->create($rfq);
            try {
                $this->mailer->send($rfq);
            } catch (\Throwable $exception) {
                error_log('RFQ email failed after storage: ' . $exception->getMessage());
            }
            $_SESSION['flash_success'] = "Thank you, {$rfq['name']}! Your RFQ has been received. Our sales team will contact you within 12 hours.";
        } catch (\Throwable $exception) {
            error_log('RFQ submission failed: ' . $exception->getMessage());
            $_SESSION['flash_error'] = 'We could not submit your RFQ right now. Please try again or contact us on WhatsApp.';
        }

        header('Location: ./#rfq');
        exit;
    }

    private function csrfToken(): string
    {
        return $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
    }

    private function redirectWithError(string $message): never
    {
        $_SESSION['flash_error'] = $message;
        header('Location: ./#rfq');
        exit;
    }

    private function cleanField(mixed $value, int $maximumLength): string
    {
        $value = trim((string) $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $maximumLength) : substr($value, 0, $maximumLength);
    }

    private function withinRateLimit(): bool
    {
        $now = time();
        $attempts = array_filter($_SESSION['rfq_attempts'] ?? [], static fn (int $time): bool => $time > $now - 3600);
        if (count($attempts) >= 5) {
            $_SESSION['rfq_attempts'] = $attempts;
            return false;
        }

        // IP-keyed rate limiting to protect against session reset evasion
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $rateLimitFile = sys_get_temp_dir() . '/km_wears_rfq_rate_limits.json';
        $fh = @fopen($rateLimitFile, 'c+');
        if ($fh && flock($fh, LOCK_EX)) {
            $raw = stream_get_contents($fh);
            $all = $raw ? json_decode($raw, true) : [];
            if (!is_array($all)) {
                $all = [];
            }
            $ipAttempts = array_filter($all[$ip] ?? [], static fn (int $time): bool => $time > $now - 3600);
            if (count($ipAttempts) >= 5) {
                flock($fh, LOCK_UN);
                fclose($fh);
                return false;
            }
            $ipAttempts[] = $now;
            $all[$ip] = $ipAttempts;
            // Purge expired records
            foreach ($all as $key => $times) {
                $all[$key] = array_filter($times, static fn (int $t): bool => $t > $now - 3600);
                if (empty($all[$key])) {
                    unset($all[$key]);
                }
            }
            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, json_encode($all));
            fflush($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
        }

        $attempts[] = $now;
        $_SESSION['rfq_attempts'] = $attempts;
        return true;
    }
}
