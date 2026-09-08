<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Shared\Enums\NotificationChannel;
use Illuminate\Database\Seeder;

/**
 * Platform-default message templates (school_id null).
 *
 * A school overrides one by saving its own row with the same key/channel/
 * locale; the resolver prefers the tenant row and falls back to these.
 *
 * `available_variables` is enforced by the renderer: a template may only
 * interpolate the placeholders declared here, so a template cannot be edited
 * into leaking an unrelated field.
 */
class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $template) {
            NotificationTemplate::query()->updateOrCreate(
                [
                    'school_id' => null,
                    'key' => $template['key'],
                    'channel' => $template['channel'],
                    'locale' => $template['locale'],
                ],
                [
                    'subject' => $template['subject'] ?? null,
                    'body' => $template['body'],
                    'available_variables' => $template['variables'],
                    'is_active' => true,
                ],
            );
        }
    }

    /** @return list<array{key: string, channel: string, locale: string, subject?: string, body: string, variables: list<string>}> */
    private function templates(): array
    {
        $invoiceVars = ['student_name', 'invoice_number', 'amount', 'balance', 'due_date', 'school_name', 'days'];
        $absenceVars = ['student_name', 'date', 'class_name', 'school_name', 'status'];
        $gradeVars = ['student_name', 'period_name', 'average', 'rank', 'class_size', 'school_name'];
        $receiptVars = ['student_name', 'receipt_number', 'amount', 'invoice_number', 'school_name', 'date'];

        return [
            // ---------------------------------------------------- reminders
            [
                'key' => 'payment_reminder', 'channel' => NotificationChannel::Email->value, 'locale' => 'fr',
                'subject' => 'Rappel de paiement — facture {{invoice_number}}',
                'body' => "Bonjour,\n\nLa facture {{invoice_number}} de {{student_name}} d'un montant de {{amount}} arrive à échéance le {{due_date}}.\n\nSolde restant : {{balance}}.\n\nCordialement,\n{{school_name}}",
                'variables' => $invoiceVars,
            ],
            [
                'key' => 'payment_reminder', 'channel' => NotificationChannel::Sms->value, 'locale' => 'fr',
                'body' => '{{school_name}} : facture {{invoice_number}} de {{student_name}}, solde {{balance}}, échéance {{due_date}}.',
                'variables' => $invoiceVars,
            ],
            [
                'key' => 'payment_reminder', 'channel' => NotificationChannel::InApp->value, 'locale' => 'fr',
                'subject' => 'Rappel de paiement',
                'body' => 'La facture {{invoice_number}} ({{balance}}) arrive à échéance le {{due_date}}.',
                'variables' => $invoiceVars,
            ],
            [
                'key' => 'payment_overdue', 'channel' => NotificationChannel::Email->value, 'locale' => 'fr',
                'subject' => 'Facture {{invoice_number}} en retard',
                'body' => "Bonjour,\n\nLa facture {{invoice_number}} de {{student_name}} est en retard de {{days}} jour(s).\n\nSolde restant : {{balance}}.\n\nCordialement,\n{{school_name}}",
                'variables' => $invoiceVars,
            ],

            // ---------------------------------------------------- attendance
            [
                'key' => 'absence_alert', 'channel' => NotificationChannel::Email->value, 'locale' => 'fr',
                'subject' => 'Absence de {{student_name}} le {{date}}',
                'body' => "Bonjour,\n\n{{student_name}} a été porté(e) {{status}} le {{date}} en {{class_name}}.\n\nVous pouvez justifier cette absence depuis votre espace parent.\n\nCordialement,\n{{school_name}}",
                'variables' => $absenceVars,
            ],
            [
                'key' => 'absence_alert', 'channel' => NotificationChannel::Sms->value, 'locale' => 'fr',
                'body' => '{{school_name}} : {{student_name}} a été porté(e) {{status}} le {{date}}.',
                'variables' => $absenceVars,
            ],
            [
                'key' => 'absence_alert', 'channel' => NotificationChannel::InApp->value, 'locale' => 'fr',
                'subject' => 'Absence signalée',
                'body' => '{{student_name}} — {{status}} le {{date}} ({{class_name}}).',
                'variables' => $absenceVars,
            ],

            // --------------------------------------------------------- money
            [
                'key' => 'receipt_issued', 'channel' => NotificationChannel::Email->value, 'locale' => 'fr',
                'subject' => 'Reçu {{receipt_number}}',
                'body' => "Bonjour,\n\nNous confirmons la réception de {{amount}} pour la facture {{invoice_number}} de {{student_name}}, le {{date}}.\n\nVotre reçu {{receipt_number}} est disponible dans votre espace.\n\nCordialement,\n{{school_name}}",
                'variables' => $receiptVars,
            ],
            [
                'key' => 'receipt_issued', 'channel' => NotificationChannel::InApp->value, 'locale' => 'fr',
                'subject' => 'Paiement confirmé',
                'body' => 'Paiement de {{amount}} confirmé — reçu {{receipt_number}}.',
                'variables' => $receiptVars,
            ],

            // ---------------------------------------------------- academics
            [
                'key' => 'report_card_published', 'channel' => NotificationChannel::Email->value, 'locale' => 'fr',
                'subject' => 'Bulletin de {{student_name}} — {{period_name}}',
                'body' => "Bonjour,\n\nLe bulletin de {{student_name}} pour {{period_name}} est disponible.\n\nMoyenne générale : {{average}} — rang {{rank}} sur {{class_size}}.\n\nCordialement,\n{{school_name}}",
                'variables' => $gradeVars,
            ],
            [
                'key' => 'report_card_published', 'channel' => NotificationChannel::InApp->value, 'locale' => 'fr',
                'subject' => 'Bulletin disponible',
                'body' => 'Le bulletin de {{student_name}} pour {{period_name}} est disponible.',
                'variables' => $gradeVars,
            ],

            // ---------------------------------------------------- admissions
            [
                'key' => 'admission_received', 'channel' => NotificationChannel::Email->value, 'locale' => 'fr',
                'subject' => 'Candidature reçue — {{reference}}',
                'body' => "Bonjour,\n\nNous avons bien reçu la candidature de {{student_name}}.\n\nRéférence : {{reference}}. Conservez-la pour suivre votre dossier.\n\nCordialement,\n{{school_name}}",
                'variables' => ['student_name', 'reference', 'school_name'],
            ],
            [
                'key' => 'admission_decision', 'channel' => NotificationChannel::Email->value, 'locale' => 'fr',
                'subject' => 'Décision concernant la candidature {{reference}}',
                'body' => "Bonjour,\n\nLa candidature {{reference}} de {{student_name}} a été {{decision}}.\n\n{{reason}}\n\nCordialement,\n{{school_name}}",
                'variables' => ['student_name', 'reference', 'decision', 'reason', 'school_name'],
            ],

            // --------------------------------------------------- english set
            [
                'key' => 'payment_reminder', 'channel' => NotificationChannel::Email->value, 'locale' => 'en',
                'subject' => 'Payment reminder — invoice {{invoice_number}}',
                'body' => "Hello,\n\nInvoice {{invoice_number}} for {{student_name}}, amounting to {{amount}}, is due on {{due_date}}.\n\nOutstanding balance: {{balance}}.\n\nKind regards,\n{{school_name}}",
                'variables' => $invoiceVars,
            ],
            [
                'key' => 'absence_alert', 'channel' => NotificationChannel::Email->value, 'locale' => 'en',
                'subject' => '{{student_name}} marked {{status}} on {{date}}',
                'body' => "Hello,\n\n{{student_name}} was marked {{status}} on {{date}} in {{class_name}}.\n\nYou can submit a justification from your parent portal.\n\nKind regards,\n{{school_name}}",
                'variables' => $absenceVars,
            ],
        ];
    }
}
