<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;

/**
 * Turkish labels for security audit list action, actor type, and outcome.
 *
 * Domain enum / stored codes stay unchanged. Unknown codes fall back safely.
 */
final class SecurityAuditListLabels
{
    /**
     * @var array<string, string>
     */
    private const ACTION = [
        'user_registered' => 'Kullanıcı kaydı',
        'email_verified' => 'E-posta doğrulandı',
        'login_succeeded' => 'Giriş başarılı',
        'password_reset_completed' => 'Parola sıfırlama tamamlandı',
        'password_changed' => 'Parola değiştirildi',
        'role_changed' => 'Rol değiştirildi',
        'status_changed' => 'Durum değiştirildi',
        'super_admin_bootstrapped' => 'Süper yönetici önyüklendi',
        'institution_created' => 'Kurum oluşturuldu',
        'institution_branch_created' => 'Kurum şubesi oluşturuldu',
        'institution_branch_updated' => 'Kurum şubesi güncellendi',
        'institution_branch_status_changed' => 'Kurum şubesi durumu değiştirildi',
        'institution_status_changed' => 'Kurum durumu değiştirildi',
        'institution_member_added' => 'Kurum üyesi eklendi',
        'institution_member_role_changed' => 'Kurum üye rolü değiştirildi',
        'institution_member_suspended' => 'Kurum üyesi askıya alındı',
        'institution_member_reactivated' => 'Kurum üyesi yeniden etkinleştirildi',
        'institution_member_ended' => 'Kurum üyeliği sonlandırıldı',
        'institution_teacher_invited' => 'Kurum öğretmeni davet edildi',
        'institution_teacher_invite_resent' => 'Kurum öğretmen daveti yeniden gönderildi',
        'institution_teacher_invite_revoked' => 'Kurum öğretmen daveti iptal edildi',
        'institution_teacher_invite_accepted' => 'Kurum öğretmen daveti kabul edildi',
        'institution_student_invited' => 'Kurum öğrencisi davet edildi',
        'institution_student_invite_resent' => 'Kurum öğrenci daveti yeniden gönderildi',
        'institution_student_invite_revoked' => 'Kurum öğrenci daveti iptal edildi',
        'institution_student_invite_accepted' => 'Kurum öğrenci daveti kabul edildi',
        'academic_year_created' => 'Akademik yıl oluşturuldu',
        'academic_year_activated' => 'Akademik yıl etkinleştirildi',
        'academic_year_closed' => 'Akademik yıl kapatıldı',
        'classroom_created' => 'Sınıf oluşturuldu',
        'classroom_updated' => 'Sınıf güncellendi',
        'classroom_archived' => 'Sınıf arşivlendi',
        'classroom_teacher_assigned' => 'Sınıf öğretmeni atandı',
        'classroom_teacher_role_changed' => 'Sınıf öğretmen rolü değiştirildi',
        'classroom_teacher_assignment_ended' => 'Sınıf öğretmen ataması sonlandırıldı',
        'classroom_student_enrolled' => 'Sınıfa öğrenci kaydedildi',
        'classroom_student_transferred' => 'Sınıf öğrencisi transfer edildi',
        'classroom_student_enrollment_ended' => 'Sınıf öğrenci kaydı sonlandırıldı',
        'subject_created' => 'Ders oluşturuldu',
        'subject_updated' => 'Ders güncellendi',
        'subject_archived' => 'Ders arşivlendi',
        'subject_deleted' => 'Ders silindi',
        'curriculum_access_gate_updated' => 'Müfredat erişim kapısı güncellendi',
        'curriculum_created' => 'Müfredat oluşturuldu',
        'curriculum_program_updated' => 'Müfredat programı güncellendi',
        'curriculum_published' => 'Müfredat yayınlandı',
        'curriculum_retired' => 'Müfredat emekli edildi',
        'curriculum_draft_deleted' => 'Müfredat taslağı silindi',
        'curriculum_cloned' => 'Müfredat klonlandı',
        'curriculum_unit_created' => 'Müfredat ünitesi oluşturuldu',
        'curriculum_unit_updated' => 'Müfredat ünitesi güncellendi',
        'curriculum_unit_reordered' => 'Müfredat ünitesi yeniden sıralandı',
        'curriculum_unit_archived' => 'Müfredat ünitesi arşivlendi',
        'curriculum_topic_created' => 'Müfredat konusu oluşturuldu',
        'curriculum_topic_updated' => 'Müfredat konusu güncellendi',
        'curriculum_topic_reordered' => 'Müfredat konusu yeniden sıralandı',
        'curriculum_topic_archived' => 'Müfredat konusu arşivlendi',
        'classroom_course_created' => 'Sınıf dersi oluşturuldu',
        'classroom_course_updated' => 'Sınıf dersi güncellendi',
        'classroom_course_curriculum_changed' => 'Sınıf dersi müfredatı değiştirildi',
        'classroom_course_archived' => 'Sınıf dersi arşivlendi',
        'course_teacher_assigned' => 'Ders öğretmeni atandı',
        'course_teacher_assignment_ended' => 'Ders öğretmen ataması sonlandırıldı',
        'curriculum_learning_outcome_created' => 'Müfredat kazanımı oluşturuldu',
        'curriculum_learning_outcome_updated' => 'Müfredat kazanımı güncellendi',
        'curriculum_learning_outcome_reordered' => 'Müfredat kazanımı yeniden sıralandı',
        'curriculum_learning_outcome_archived' => 'Müfredat kazanımı arşivlendi',
        'curriculum_official_program_reconciled' => 'Resmî müfredat programı uzlaştırıldı',
        'question_created' => 'Soru oluşturuldu',
        'questions_bulk_imported' => 'Sorular toplu içe aktarıldı',
        'question_revision_created' => 'Soru sürümü oluşturuldu',
        'question_submitted_for_review' => 'Soru incelemeye gönderildi',
        'question_returned_to_draft' => 'Soru taslağa alındı',
        'question_published' => 'Soru yayınlandı',
        'question_archived' => 'Soru arşivlendi',
        'assessment_created' => 'Test oluşturuldu',
        'assessment_revision_created' => 'Test sürümü oluşturuldu',
        'assessment_submitted_for_review' => 'Test incelemeye gönderildi',
        'assessment_returned_to_draft' => 'Test taslağa alındı',
        'assessment_published' => 'Test yayınlandı',
        'assessment_archived' => 'Test arşivlendi',
        'assessment_publication_created' => 'Test yayını oluşturuldu',
        'assessment_delivery_created' => 'Test teslimatı oluşturuldu',
        'assessment_delivery_draft_updated' => 'Test teslimat taslağı güncellendi',
        'assessment_delivery_activated' => 'Test teslimatı etkinleştirildi',
        'assessment_delivery_closed' => 'Test teslimatı kapatıldı',
        'assessment_delivery_cancelled' => 'Test teslimatı iptal edildi',
        'assessment_delivery_recipient_added' => 'Test teslimat alıcısı eklendi',
        'assessment_delivery_recipient_revoked' => 'Test teslimat alıcısı iptal edildi',
        'assessment_attempt_started' => 'Test denemesi başladı',
        'assessment_attempt_answer_saved' => 'Test denemesi cevabı kaydedildi',
        'assessment_attempt_submitted' => 'Test denemesi gönderildi',
        'assessment_attempt_expired' => 'Test denemesinin süresi doldu',
        'assessment_attempt_cancelled' => 'Test denemesi iptal edildi',
        'assessment_scoring_started' => 'Test puanlama başladı',
        'assessment_scoring_completed' => 'Test puanlama tamamlandı',
        'assessment_scoring_pending_manual' => 'Test puanlama manuel bekliyor',
        'assessment_scoring_failed' => 'Test puanlama başarısız',
        'assessment_item_manually_graded' => 'Test maddesi elle notlandı',
        'assessment_regraded' => 'Test yeniden puanlandı',
        'assessment_result_released' => 'Test sonucu açıldı',
        'assessment_result_superseded' => 'Test sonucu yerine yenisi geldi',
        'assessment_result_withdrawn' => 'Test sonucu geri çekildi',
        'assessment_result_review_policy_created' => 'Test sonuç inceleme politikası oluşturuldu',
        'assessment_result_review_policy_updated' => 'Test sonuç inceleme politikası güncellendi',
        'assessment_result_review_policy_activated' => 'Test sonuç inceleme politikası etkinleştirildi',
        'assessment_result_review_policy_superseded' => 'Test sonuç inceleme politikası yerine yenisi geldi',
        'assessment_analytics_viewed' => 'Test analitiği görüntülendi',
        'classroom_analytics_viewed' => 'Sınıf analitiği görüntülendi',
        'student_analytics_viewed' => 'Öğrenci analitiği görüntülendi',
        'question_analytics_viewed' => 'Soru analitiği görüntülendi',
        'learning_outcome_analytics_viewed' => 'Kazanım analitiği görüntülendi',
        'learning_content_created' => 'Öğrenme içeriği oluşturuldu',
        'learning_content_revision_created' => 'Öğrenme içeriği sürümü oluşturuldu',
        'learning_content_revision_updated' => 'Öğrenme içeriği sürümü güncellendi',
        'learning_content_package_imported' => 'Öğrenme içeriği paketi içe aktarıldı',
        'learning_content_submitted_for_review' => 'Öğrenme içeriği incelemeye gönderildi',
        'learning_content_returned_to_draft' => 'Öğrenme içeriği taslağa alındı',
        'learning_content_published' => 'Öğrenme içeriği yayınlandı',
        'learning_content_archived' => 'Öğrenme içeriği arşivlendi',
        'learning_content_cloned' => 'Öğrenme içeriği klonlandı',
        'learning_content_outcomes_aligned' => 'Öğrenme içeriği kazanımları hizalandı',
        'learning_content_asset_attached' => 'Öğrenme içeriği varlığı bağlandı',
        'learning_content_asset_detached' => 'Öğrenme içeriği varlığı ayrıldı',
        'stored_media_asset_registered' => 'Saklanan medya varlığı kaydedildi',
        'stored_media_asset_scan_updated' => 'Saklanan medya taraması güncellendi',
        'stored_media_asset_marked_ready' => 'Saklanan medya hazır işaretlendi',
        'stored_media_asset_quarantined' => 'Saklanan medya karantinaya alındı',
        'stored_media_asset_archived' => 'Saklanan medya arşivlendi',
        'access_package_created' => 'Erişim paketi oluşturuldu',
        'access_package_updated' => 'Erişim paketi güncellendi',
        'access_package_activated' => 'Erişim paketi etkinleştirildi',
        'access_package_retired' => 'Erişim paketi emekli edildi',
        'access_package_version_created' => 'Erişim paketi sürümü oluşturuldu',
        'access_package_version_updated' => 'Erişim paketi sürümü güncellendi',
        'access_package_version_activated' => 'Erişim paketi sürümü etkinleştirildi',
        'access_package_version_superseded' => 'Erişim paketi sürümü yerine yenisi geldi',
        'access_package_grant_added' => 'Erişim paketi hakkı eklendi',
        'access_package_grant_removed' => 'Erişim paketi hakkı kaldırıldı',
        'access_license_created' => 'Erişim lisansı oluşturuldu',
        'access_license_activated' => 'Erişim lisansı etkinleştirildi',
        'access_license_suspended' => 'Erişim lisansı askıya alındı',
        'access_license_reactivated' => 'Erişim lisansı yeniden etkinleştirildi',
        'access_license_revoked' => 'Erişim lisansı iptal edildi',
        'access_license_expired' => 'Erişim lisansının süresi doldu',
        'institution_license_seat_assigned' => 'Kurum lisans koltuğu atandı',
        'institution_license_seat_revoked' => 'Kurum lisans koltuğu iptal edildi',
        'learning_content_access_policy_set' => 'Öğrenme içeriği erişim politikası ayarlandı',
        'assessment_access_policy_set' => 'Test erişim politikası ayarlandı',
        'catalog_topic_lesson_created' => 'Katalog konu dersi oluşturuldu',
        'catalog_topic_lesson_updated' => 'Katalog konu dersi güncellendi',
        'catalog_topic_lesson_published' => 'Katalog konu dersi yayınlandı',
        'catalog_topic_lesson_archived' => 'Katalog konu dersi arşivlendi',
        'catalog_topic_assessment_created' => 'Katalog konu testi oluşturuldu',
        'catalog_topic_assessment_updated' => 'Katalog konu testi güncellendi',
        'catalog_topic_assessment_published' => 'Katalog konu testi yayınlandı',
        'catalog_topic_assessment_archived' => 'Katalog konu testi arşivlendi',
        'catalog_subject_canonical_mapped' => 'Katalog dersi kanonik eşlendi',
        'commercial_offer_created' => 'Ticari teklif oluşturuldu',
        'commercial_offer_updated' => 'Ticari teklif güncellendi',
        'commercial_offer_activated' => 'Ticari teklif etkinleştirildi',
        'commercial_offer_retired' => 'Ticari teklif emekli edildi',
        'commerce_order_created' => 'Ticaret siparişi oluşturuldu',
        'commerce_order_cancelled' => 'Ticaret siparişi iptal edildi',
        'commerce_order_expired' => 'Ticaret siparişinin süresi doldu',
        'commerce_order_paid' => 'Ticaret siparişi ödendi',
        'payment_attempt_started' => 'Ödeme denemesi başladı',
        'payment_authorized' => 'Ödeme yetkilendirildi',
        'payment_captured' => 'Ödeme tahsil edildi',
        'payment_failed' => 'Ödeme başarısız',
        'payment_cancelled' => 'Ödeme iptal edildi',
        'payment_refund_requested' => 'Ödeme iadesi talep edildi',
        'payment_refund_succeeded' => 'Ödeme iadesi tamamlandı',
        'payment_refund_failed' => 'Ödeme iadesi başarısız',
        'payment_checkout_started' => 'Ödeme sayfası başladı',
        'payment_checkout_provider_accepted' => 'Ödeme sayfası sağlayıcı kabulü',
        'payment_checkout_provider_rejected' => 'Ödeme sayfası sağlayıcı reddi',
        'payment_webhook_received' => 'Ödeme webhook alındı',
        'payment_webhook_processed' => 'Ödeme webhook işlendi',
        'payment_webhook_rejected' => 'Ödeme webhook reddedildi',
        'payment_webhook_integrity_failed' => 'Ödeme webhook bütünlüğü başarısız',
        'payment_webhook_batch_processed' => 'Ödeme webhook toplu işlendi',
        'payment_webhook_dead_letter_requeued' => 'Ödeme webhook ölü mektubu yeniden kuyruğa alındı',
        'payment_reconciliation_started' => 'Ödeme uzlaştırma başladı',
        'payment_reconciliation_completed' => 'Ödeme uzlaştırma tamamlandı',
        'payment_reconciliation_discrepancy_found' => 'Ödeme uzlaştırma tutarsızlığı bulundu',
        'payment_reconciliation_action_applied' => 'Ödeme uzlaştırma eylemi uygulandı',
        'commerce_subscription_created' => 'Ticaret aboneliği oluşturuldu',
        'commerce_subscription_activated' => 'Ticaret aboneliği etkinleştirildi',
        'commerce_subscription_renewed' => 'Ticaret aboneliği yenilendi',
        'commerce_subscription_cancellation_scheduled' => 'Ticaret aboneliği iptali zamanlandı',
        'commerce_subscription_cancelled' => 'Ticaret aboneliği iptal edildi',
        'commerce_subscription_expired' => 'Ticaret aboneliğinin süresi doldu',
        'commerce_fulfillment_completed' => 'Ticaret karşılama tamamlandı',
        'commerce_fulfillment_failed' => 'Ticaret karşılama başarısız',
        'commerce_fulfillment_reversed' => 'Ticaret karşılama geri alındı',
        'phone_verification_claim_created' => 'Telefon doğrulama talebi oluşturuldu',
        'phone_verification_failed' => 'Telefon doğrulama başarısız',
        'phone_verification_revoked' => 'Telefon doğrulama iptal edildi',
        'phone_bound' => 'Telefon bağlandı',
        'teacher_application_submitted' => 'Öğretmen başvurusu gönderildi',
        'teacher_application_withdrawn' => 'Öğretmen başvurusu geri çekildi',
        'teacher_application_approved' => 'Öğretmen başvurusu onaylandı',
        'teacher_application_rejected' => 'Öğretmen başvurusu reddedildi',
        'institution_application_submitted' => 'Kurum başvurusu gönderildi',
        'institution_application_withdrawn' => 'Kurum başvurusu geri çekildi',
        'institution_application_approved' => 'Kurum başvurusu onaylandı',
        'institution_application_rejected' => 'Kurum başvurusu reddedildi',
        'parent_student_link_requested' => 'Veli-öğrenci bağlantısı talep edildi',
        'parent_student_link_verified' => 'Veli-öğrenci bağlantısı doğrulandı',
        'parent_student_link_ended' => 'Veli-öğrenci bağlantısı sonlandırıldı',
        'parent_student_link_accept_failed' => 'Veli-öğrenci bağlantısı kabulü başarısız',
        'link_code_created' => 'Bağlantı kodu oluşturuldu',
        'link_code_revoked' => 'Bağlantı kodu iptal edildi',
        'parent_link_activated' => 'Veli bağlantısı etkinleştirildi',
        'parent_link_revoked' => 'Veli bağlantısı iptal edildi',
        'module_permission_changed' => 'Modül izni değiştirildi',
        'homework_definition_draft_created' => 'Ödev taslağı oluşturuldu',
        'homework_definition_draft_updated' => 'Ödev taslağı güncellendi',
        'homework_assignment_created' => 'Ödev ataması oluşturuldu',
        'homework_submission_draft_saved' => 'Ödev teslim taslağı kaydedildi',
        'homework_submission_submitted' => 'Ödev teslim edildi',
        'homework_evaluation_recorded' => 'Ödev değerlendirmesi kaydedildi',
    ];

    /**
     * @var array<string, string>
     */
    private const ACTOR_TYPE = [
        SecurityAuditActorType::User->value => 'Kullanıcı',
        SecurityAuditActorType::System->value => 'Sistem',
        SecurityAuditActorType::Cli->value => 'CLI',
    ];

    /**
     * @var array<string, array{0: string, 1: 'neutral'|'success'|'warning'|'danger'|'limited'}>
     */
    private const OUTCOME = [
        SecurityAuditOutcome::Success->value => ['Başarılı', 'success'],
        SecurityAuditOutcome::Failure->value => ['Başarısız', 'danger'],
    ];

    public function action(string $code): string
    {
        return self::ACTION[$code] ?? $code;
    }

    public function actorType(string $code): string
    {
        return self::ACTOR_TYPE[$code] ?? $code;
    }

    /**
     * @return array{label: string, variant: 'neutral'|'success'|'warning'|'danger'|'limited'}
     */
    public function outcome(string $code): array
    {
        $row = self::OUTCOME[$code] ?? ['Bilinmiyor', 'neutral'];

        return [
            'label' => $row[0],
            'variant' => $row[1],
        ];
    }

    /**
     * @return list<string>
     */
    public static function knownActionCodes(): array
    {
        return array_keys(self::ACTION);
    }
}