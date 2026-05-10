<?php
/*
 * MensaManager - Digitale Schulverpflegung
 * Copyright (C) 2026 Lukas Trausch
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see [https://www.gnu.org/licenses/](https://www.gnu.org/licenses/).
 */

// Sicherheitspruefung: Direkten Aufruf der Datei ueber den Browser verhindern
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    header('HTTP/1.0 403 Forbidden');
    die('Direct access not permitted');
}

require_once __DIR__ . '/mm_bootstrap.php';
require_once __DIR__ . '/mailer/Exception.php';
require_once __DIR__ . '/mailer/PHPMailer.php';
require_once __DIR__ . '/mailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

function portal_mail_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function portal_mailer_apply_transport(PHPMailer $mail, array $config)
{
    $encryption = strtolower(trim((string) ($config['encryption'] ?? 'tls')));
    $port = (int) ($config['port'] ?? 587);

    $mail->isSMTP();
    $mail->Host = (string) $config['host'];
    $mail->SMTPAuth = true;
    $mail->Username = (string) $config['username'];
    $mail->Password = (string) $config['password'];
    $mail->Port = $port;
    $mail->SMTPAutoTLS = true;

    if ($encryption === 'ssl' || $encryption === 'smtps' || $port === 465) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        return;
    }

    if ($encryption === 'none' || $encryption === 'off' || $encryption === 'false' || $encryption === '0') {
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;
        return;
    }

    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
}

function sendOrderConfirmationEmail($toEmail, $toName, $actionType, $amount, $paymentMethod, $details = [], $paymentPin = null)
{
    $mail = new PHPMailer(true);

    try {
        $config = mm_get_mailer_config();
        portal_mailer_apply_transport($mail, $config);

        $mail->setFrom($config['from_address'], $config['from_name']);
        if ($config['reply_to_address'] !== '') {
            $mail->addReplyTo($config['reply_to_address'], $config['reply_to_name']);
        }
        $mail->addAddress((string) $toEmail, (string) $toName);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = PHPMailer::ENCODING_BASE64;

        $title = 'Bestellbestätigung';
        $detailsHtml = '';

        switch ($actionType) {
            case 'topup':
                $title = 'Bestätigung: Guthabenaufladung';
                $detailsHtml = '<li><strong>Aktion:</strong> Aufladung des Familien-Guthabens</li>';
                break;

            case 'order_card':
                $title = 'Bestätigung: Neues Schülerprofil';
                $studentName = trim((string) ($details['firstName'] ?? '') . ' ' . (string) ($details['lastName'] ?? ''));
                $detailsHtml = '<li><strong>Aktion:</strong> Anlage eines neuen Profils inkl. Chipkarte</li>'
                    . '<li><strong>Schüler/in:</strong> ' . portal_mail_escape($studentName) . '</li>';
                break;

            case 'buy_abo':
                $title = 'Bestätigung: Mensa-Abo';
                $aboType = (($details['type'] ?? '') === 'halb') ? 'Halbjahresabo' : 'Ganzjahresabo';
                $days = is_array($details['days'] ?? null) ? implode(', ', $details['days']) : '';
                $detailsHtml = '<li><strong>Aktion:</strong> Kauf eines Mensa-Abos (' . portal_mail_escape($aboType) . ')</li>'
                    . '<li><strong>Wochentage:</strong> ' . portal_mail_escape($days) . '</li>';
                break;

            case 'reorder_card':
                $title = 'Bestätigung: Ersatzkarte';
                $detailsHtml = '<li><strong>Aktion:</strong> Beantragung einer neuen Ersatzkarte</li>';
                break;
        }

        $mail->Subject = 'MensaPay: ' . $title;

        $escapedPaymentMethod = portal_mail_escape($paymentMethod);
        $formattedAmount = number_format((float) $amount, 2, ',', '.') . ' EUR';

        $bankTransferHtml = '';
        if ($paymentMethod === 'Überweisung' && !empty($paymentPin)) {
            $bankTransferHtml = '
            <div style="background-color: #fffbeb; border: 2px solid #fde68a; border-radius: 12px; padding: 20px; margin-top: 25px;">
                <h3 style="color: #b45309; margin-top: 0;">Zahlung ausstehend</h3>
                <p style="color: #92400e; margin-bottom: 15px;">Bitte überweise den Gesamtbetrag auf das folgende Konto, damit wir deine Bestellung abschließen können:</p>
                <table style="width: 100%; color: #78350f; font-family: monospace; font-size: 14px; margin-bottom: 15px;">
                    <tr><td style="padding: 4px 0;"><strong>Empfänger:</strong></td><td style="text-align: right;">Gymnasium Hohenschwangau</td></tr>
                    <tr><td style="padding: 4px 0;"><strong>IBAN:</strong></td><td style="text-align: right;">DE12 3456 7890 1234 5678 90</td></tr>
                    <tr><td style="padding: 4px 0;"><strong>BIC:</strong></td><td style="text-align: right;">BYLADEM1ALG</td></tr>
                </table>
                <div style="background-color: #ffffff; padding: 15px; border-radius: 8px; border: 1px solid #fcd34d; text-align: center;">
                    <p style="margin: 0; font-size: 12px; color: #b45309; text-transform: uppercase; font-weight: bold; letter-spacing: 1px;">Verwendungszweck (sehr wichtig)</p>
                    <p style="margin: 5px 0 0 0; font-size: 24px; font-weight: bold; color: #1e293b; letter-spacing: 2px;">MENSA ' . portal_mail_escape($paymentPin) . '</p>
                </div>
                <p style="color: #92400e; font-size: 12px; margin-top: 15px; text-align: center;">Deine Transaktion wird automatisch bearbeitet, sobald das Geld eingegangen ist.</p>
            </div>';
        } elseif ($paymentMethod === 'Guthaben') {
            $bankTransferHtml = '
            <div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 15px; margin-top: 25px; text-align: center;">
                <p style="color: #166534; margin: 0; font-weight: bold;">✅ Erfolgreich mit Guthaben bezahlt.</p>
            </div>';
        } else {
            $bankTransferHtml = '
            <div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 15px; margin-top: 25px; text-align: center;">
                <p style="color: #166534; margin: 0; font-weight: bold;">✅ Zahlung via ' . $escapedPaymentMethod . ' erfolgreich abgeschlossen.</p>
            </div>';
        }

        $mail->Body = '
        <div style="font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; background-color: #f8fafc; padding: 40px 20px; color: #334155; line-height: 1.6;">
            <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);">
                <div style="background-color: #2563eb; padding: 30px; text-align: center;">
                    <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: 800;">MensaPay</h1>
                    <p style="margin: 5px 0 0 0; color: #bfdbfe; font-size: 14px;">Das Ho\'gauer Schulverpflegungs-Portal</p>
                </div>

                <div style="padding: 40px 30px;">
                    <h2 style="margin-top: 0; color: #1e293b; font-size: 20px;">Hallo ' . portal_mail_escape($toName) . ',</h2>
                    <p>Vielen Dank für deine Transaktion. Hier ist die Zusammenfassung deiner Bestellung:</p>

                    <div style="background-color: #f1f5f9; padding: 20px; border-radius: 12px; margin: 25px 0;">
                        <ul style="list-style: none; padding: 0; margin: 0; color: #475569;">
                            ' . $detailsHtml . '
                            <li style="margin-top: 10px; border-top: 1px solid #cbd5e1; padding-top: 10px; display: flex; justify-content: space-between;">
                                <span>Zahlungsmethode:</span>
                                <strong>' . $escapedPaymentMethod . '</strong>
                            </li>
                        </ul>
                        <div style="margin-top: 15px; background-color: #e2e8f0; border-radius: 8px; padding: 15px; text-align: center;">
                            <span style="font-size: 14px; color: #64748b; text-transform: uppercase; font-weight: 700;">Gesamtbetrag</span>
                            <div style="font-size: 28px; font-weight: 800; color: #0f172a; margin-top: 5px;">' . portal_mail_escape($formattedAmount) . '</div>
                        </div>
                    </div>

                    ' . $bankTransferHtml . '

                    <div style="margin-top: 40px; border-top: 1px solid #f1f5f9; padding-top: 20px;">
                        <p style="font-size: 14px; color: #64748b;">Du kannst den Status deiner Bestellungen und dein Guthaben jederzeit in deinem MensaPay-Dashboard einsehen.</p>
                        <p style="font-size: 14px; color: #64748b; margin-top: 20px;">Viele Grüße,<br><strong>Dein Hogauer Mensa-Team</strong></p>
                    </div>
                </div>

                <div style="background-color: #f8fafc; padding: 20px; text-align: center; border-top: 1px solid #f1f5f9;">
                    <p style="margin: 0; font-size: 12px; color: #94a3b8;">
                        &copy; ' . date('Y') . ' Gymnasium Hohenschwangau<br>
                        Colomanstraße 10 | 87645 Schwangau
                    </p>
                </div>
            </div>
        </div>';

        $mail->AltBody = "Hallo $toName,\n\n"
            . "vielen Dank für deine Bestellung bei MensaPay.\n\n"
            . "Aktion: $title\n"
            . "Gesamtbetrag: $formattedAmount\n"
            . "Zahlungsmethode: $paymentMethod\n\n";

        if ($paymentMethod === 'Überweisung' && !empty($paymentPin)) {
            $mail->AltBody .= "WICHTIG: Bitte überweise den Betrag auf das Konto vom Gymnasium Hohenschwangau.\n"
                . "Verwendungszweck: MENSA $paymentPin\n"
                . "IBAN: DE12 3456 7890 1234 5678 90\n"
                . "BIC: BYLADEM1ALG\n\n";
        }

        $mail->AltBody .= "Viele Grüße,\nDein Ho'gauer Mensa-Team";

        return $mail->send();
    } catch (Throwable $e) {
        error_log('Email konnte nicht gesendet werden. Mailer Error: ' . $mail->ErrorInfo . ' | Exception: ' . $e->getMessage());
        return false;
    }
}
