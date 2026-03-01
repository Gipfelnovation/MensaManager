<?php
// Sicherheitsprüfung: Direkten Aufruf der Datei über den Browser verhindern
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    header('HTTP/1.0 403 Forbidden');
    die('Direct access not permitted');
}

require_once __DIR__ . '/mailer/Exception.php';
require_once __DIR__ . '/mailer/PHPMailer.php';
require_once __DIR__ . '/mailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;
/**
 * Versendet eine ansprechende HTML-Bestätigungsemail.
 *
 * @param string $toEmail Empfänger E-Mail-Adresse
 * @param string $toName Name des Empfängers
 * @param string $actionType Typ der Aktion (topup, order_card, buy_abo, reorder_card)
 * @param float $amount Rechnungsbetrag
 * @param string $paymentMethod Zahlungsmethode
 * @param array $details Spezifische Details zur Bestellung (z.B. Abo-Art, Schülername)
 * @param string|null $paymentPin Der generierte PIN für Überweisungen
 * @return bool True bei Erfolg, False bei Fehler
 */
function sendOrderConfirmationEmail($toEmail, $toName, $actionType, $amount, $paymentMethod, $details = [], $paymentPin = null) {
    
    $mail = new PHPMailer(true);

    try {
        // --- SMTP KONFIGURATION ---
        $mail->isSMTP();
        $mail->Host       = '***REMOVED***';
        $mail->SMTPAuth   = true;
        $mail->Username   = '***REMOVED***';
        $mail->Password   = '***REMOVED***';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('noreply@mensamanager.de', 'Hogau Mensaportal');
        $mail->addReplyTo('verwaltung@gymnasium-hohenschwangau.de', 'Gymnasium Hohenschwangau');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';

        // --- TITEL UND DETAILS ZUSAMMENSTELLEN ---
        $title = "Bestellbestätigung";
        $detailsHtml = "";

        switch ($actionType) {
            case 'topup':
                $title = "Bestätigung: Guthabenaufladung";
                $detailsHtml = "<li><strong>Aktion:</strong> Aufladung des Familien-Guthabens</li>";
                break;
            case 'order_card':
                $title = "Bestätigung: Neues Schülerprofil";
                $studentName = htmlspecialchars($details['firstName'] . ' ' . $details['lastName']);
                $detailsHtml = "<li><strong>Aktion:</strong> Anlage eines neuen Profils inkl. Chipkarte</li>
                                <li><strong>Schüler/in:</strong> $studentName</li>";
                break;
            case 'buy_abo':
                $title = "Bestätigung: Mensa-Abo";
                $aboType = isset($details['type']) && $details['type'] === 'halb' ? 'Halbjahresabo' : 'Ganzjahresabo';
                $days = isset($details['days']) ? implode(', ', $details['days']) : '';
                $detailsHtml = "<li><strong>Aktion:</strong> Kauf eines Mensa-Abos ($aboType)</li>
                                <li><strong>Wochentage:</strong> $days</li>";
                break;
            case 'reorder_card':
                $title = "Bestätigung: Ersatzkarte";
                $detailsHtml = "<li><strong>Aktion:</strong> Beantragung einer neuen Ersatzkarte</li>";
                break;
        }

        $mail->Subject = "MensaPay: $title";

        // --- ÜBERWEISUNGS-HINWEIS GENERIEREN ---
        $bankTransferHtml = "";
        if ($paymentMethod === 'Überweisung' && $paymentPin) {
            $bankTransferHtml = '
            <div style="background-color: #fffbeb; border: 2px solid #fde68a; border-radius: 12px; padding: 20px; margin-top: 25px;">
                <h3 style="color: #b45309; margin-top: 0; display: flex; align-items: center;">
                    <span style="font-size: 20px; margin-right: 8px;">⏳</span> Zahlung ausstehend
                </h3>
                <p style="color: #92400e; margin-bottom: 15px;">Bitte überweise den Gesamtbetrag auf das folgende Konto, damit wir deine Bestellung abschließen können:</p>
                <table style="width: 100%; color: #78350f; font-family: monospace; font-size: 14px; margin-bottom: 15px;">
                    <tr><td style="padding: 4px 0;"><strong>Empfänger:</strong></td><td style="text-align: right;">Gymnasium Hohenschwangau</td></tr>
                    <tr><td style="padding: 4px 0;"><strong>IBAN:</strong></td><td style="text-align: right;">DE12 3456 7890 1234 5678 90</td></tr>
                    <tr><td style="padding: 4px 0;"><strong>BIC:</strong></td><td style="text-align: right;">BYLADEM1ALG</td></tr>
                </table>
                <div style="background-color: #ffffff; padding: 15px; border-radius: 8px; border: 1px solid #fcd34d; text-align: center;">
                    <p style="margin: 0; font-size: 12px; color: #b45309; text-transform: uppercase; font-weight: bold; letter-spacing: 1px;">Verwendungszweck (Sehr Wichtig)</p>
                    <p style="margin: 5px 0 0 0; font-size: 24px; font-weight: bold; color: #1e293b; letter-spacing: 2px;">MENSA ' . htmlspecialchars($paymentPin) . '</p>
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
                <p style="color: #166534; margin: 0; font-weight: bold;">✅ Zahlung via ' . htmlspecialchars($paymentMethod) . ' erfolgreich abgeschlossen.</p>
            </div>';
        }

        $formattedAmount = number_format($amount, 2, ',', '.') . ' €';

        // --- HTML TEMPLATE ZUSAMMENBAUEN ---
        $mail->Body = '
        <div style="font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; background-color: #f8fafc; padding: 40px 20px; color: #334155; line-height: 1.6;">
            <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);">
                
                <!-- Header -->
                <div style="background-color: #2563eb; padding: 30px; text-align: center;">
                    <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: 800; tracking: tight;">MensaPay</h1>
                    <p style="margin: 5px 0 0 0; color: #bfdbfe; font-size: 14px;">Das Ho\'gauer Schulverpflegungs-Portal</p>
                </div>

                <!-- Content -->
                <div style="padding: 40px 30px;">
                    <h2 style="margin-top: 0; color: #1e293b; font-size: 20px;">Hallo ' . htmlspecialchars($toName) . ',</h2>
                    <p>vielen Dank für deine Transaktion. Hier ist die Zusammenfassung deiner Bestellung:</p>
                    
                    <div style="background-color: #f1f5f9; padding: 20px; border-radius: 12px; margin: 25px 0;">
                        <ul style="list-style: none; padding: 0; margin: 0; color: #475569;">
                            ' . $detailsHtml . '
                            <li style="margin-top: 10px; border-top: 1px solid #cbd5e1; padding-top: 10px; display: flex; justify-content: space-between;">
                                <span>Zahlungsmethode:</span>
                                <strong>' . htmlspecialchars($paymentMethod) . '</strong>
                            </li>
                        </ul>
                        <div style="margin-top: 15px; background-color: #e2e8f0; border-radius: 8px; padding: 15px; text-align: center;">
                            <span style="font-size: 14px; color: #64748b; text-transform: uppercase; font-weight: 700;">Gesamtbetrag</span>
                            <div style="font-size: 28px; font-weight: 800; color: #0f172a; margin-top: 5px;">' . $formattedAmount . '</div>
                        </div>
                    </div>

                    ' . $bankTransferHtml . '

                    <div style="margin-top: 40px; border-top: 1px solid #f1f5f9; padding-top: 20px;">
                        <p style="font-size: 14px; color: #64748b;">Du kannst den Status deiner Bestellungen und dein Guthaben jederzeit in deinem MensaPay Dashboard einsehen.</p>
                        <p style="font-size: 14px; color: #64748b; margin-top: 20px;">Viele Grüße,<br><strong>Dein Hogauer Mensa-Team</strong></p>
                    </div>
                </div>

                <!-- Footer -->
                <div style="background-color: #f8fafc; padding: 20px; text-align: center; border-top: 1px solid #f1f5f9;">
                    <p style="margin: 0; font-size: 12px; color: #94a3b8;">
                        &copy; ' . date('Y') . ' Gymnasium Hohenschwangau<br>
                        Colomanstraße 10 | 87645 Schwangau
                    </p>
                </div>
            </div>
        </div>';

        // Plain Text Alternative für Mail-Clients ohne HTML Support
        $mail->AltBody = "Hallo $toName,\n\nvielen Dank für deine Bestellung bei MensaPay.\n\n"
                       . "Aktion: $title\nGesamtbetrag: $formattedAmount\nZahlungsmethode: $paymentMethod\n\n";
        
        if ($paymentMethod === 'Überweisung' && $paymentPin) {
            $mail->AltBody .= "WICHTIG: Bitte überweise den Betrag auf das Konto vom Gymnasium Hohenschwangau.\n"
                            . "Verwendungszweck: MENSA $paymentPin\n"
                            . "IBAN: DE12 3456 7890 1234 5678 90\n\n";
        }

        $mail->AltBody .= "Viele Grüße,\nDein Ho'gauer Mensa-Team";

        return $mail->send();
    } catch (Exception $e) {
        error_log("Email konnte nicht gesendet werden. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
?>