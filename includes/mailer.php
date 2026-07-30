<?php
// ============================================================
//  Dievon – Central Mailer Bridge
//  Delegates all email functions to EmailService
// ============================================================

require_once __DIR__ . '/../services/EmailService.php';

function getEmailService(): EmailService {
    static $service = null;
    if ($service === null) {
        global $pdo;
        $service = new EmailService($pdo);
    }
    return $service;
}

function sendOrderEmail(array $order): bool {
    try {
        return getEmailService()->sendOrderPlacedAdminEmail($order);
    } catch (\Throwable $e) {
        error_log("sendOrderEmail bridge error: " . $e->getMessage());
        return false;
    }
}

function sendCustomerConfirmationEmail(array $order, string $customerEmail): bool {
    try {
        $order['customer_email'] = $customerEmail;
        return getEmailService()->sendOrderPlacedCustomerEmail($order);
    } catch (\Throwable $e) {
        error_log("sendCustomerConfirmationEmail bridge error: " . $e->getMessage());
        return false;
    }
}

function sendGenericEmail(string $toEmail, string $subject, string $htmlBody): bool {
    try {
        return getEmailService()->sendContactFormEmails('Customer', $toEmail, '', $subject, strip_tags($htmlBody));
    } catch (\Throwable $e) {
        error_log("sendGenericEmail bridge error: " . $e->getMessage());
        return false;
    }
}

function sendPaymentReceiptEmail(array $order): bool {
    try {
        return getEmailService()->sendOrderStatusEmail($order, 'Delivered');
    } catch (\Throwable $e) {
        error_log("sendPaymentReceiptEmail bridge error: " . $e->getMessage());
        return false;
    }
}