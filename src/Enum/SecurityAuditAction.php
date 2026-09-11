<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Stable, lowercase snake_case values persisted for security audit events.
 */
enum SecurityAuditAction: string
{
    case UserRegistered = 'user_registered';
    case EmailVerified = 'email_verified';
    case LoginSucceeded = 'login_succeeded';
    case PasswordResetCompleted = 'password_reset_completed';
    case PasswordChanged = 'password_changed';
    case RoleChanged = 'role_changed';
    case StatusChanged = 'status_changed';
    case SuperAdminBootstrapped = 'super_admin_bootstrapped';
    case InstitutionCreated = 'institution_created';
    case InstitutionStatusChanged = 'institution_status_changed';
    case InstitutionMemberAdded = 'institution_member_added';
    case InstitutionMemberRoleChanged = 'institution_member_role_changed';
    case InstitutionMemberSuspended = 'institution_member_suspended';
    case InstitutionMemberReactivated = 'institution_member_reactivated';
    case InstitutionMemberEnded = 'institution_member_ended';
    case AcademicYearCreated = 'academic_year_created';
    case AcademicYearActivated = 'academic_year_activated';
    case AcademicYearClosed = 'academic_year_closed';
    case ClassroomCreated = 'classroom_created';
    case ClassroomUpdated = 'classroom_updated';
    case ClassroomArchived = 'classroom_archived';
    case ClassroomTeacherAssigned = 'classroom_teacher_assigned';
    case ClassroomTeacherRoleChanged = 'classroom_teacher_role_changed';
    case ClassroomTeacherAssignmentEnded = 'classroom_teacher_assignment_ended';
    case ClassroomStudentEnrolled = 'classroom_student_enrolled';
    case ClassroomStudentTransferred = 'classroom_student_transferred';
    case ClassroomStudentEnrollmentEnded = 'classroom_student_enrollment_ended';
    case SubjectCreated = 'subject_created';
    case SubjectUpdated = 'subject_updated';
    case SubjectArchived = 'subject_archived';
    case CurriculumCreated = 'curriculum_created';
    case CurriculumPublished = 'curriculum_published';
    case CurriculumRetired = 'curriculum_retired';
    case CurriculumCloned = 'curriculum_cloned';
    case CurriculumUnitCreated = 'curriculum_unit_created';
    case CurriculumUnitUpdated = 'curriculum_unit_updated';
    case CurriculumUnitReordered = 'curriculum_unit_reordered';
    case CurriculumUnitArchived = 'curriculum_unit_archived';
    case CurriculumTopicCreated = 'curriculum_topic_created';
    case CurriculumTopicUpdated = 'curriculum_topic_updated';
    case CurriculumTopicReordered = 'curriculum_topic_reordered';
    case CurriculumTopicArchived = 'curriculum_topic_archived';
    case ClassroomCourseCreated = 'classroom_course_created';
    case ClassroomCourseUpdated = 'classroom_course_updated';
    case ClassroomCourseCurriculumChanged = 'classroom_course_curriculum_changed';
    case ClassroomCourseArchived = 'classroom_course_archived';
    case CourseTeacherAssigned = 'course_teacher_assigned';
    case CourseTeacherAssignmentEnded = 'course_teacher_assignment_ended';
    case CurriculumLearningOutcomeCreated = 'curriculum_learning_outcome_created';
    case CurriculumLearningOutcomeUpdated = 'curriculum_learning_outcome_updated';
    case CurriculumLearningOutcomeReordered = 'curriculum_learning_outcome_reordered';
    case CurriculumLearningOutcomeArchived = 'curriculum_learning_outcome_archived';
    case QuestionCreated = 'question_created';
    case QuestionRevisionCreated = 'question_revision_created';
    case QuestionSubmittedForReview = 'question_submitted_for_review';
    case QuestionReturnedToDraft = 'question_returned_to_draft';
    case QuestionPublished = 'question_published';
    case QuestionArchived = 'question_archived';
    case AssessmentCreated = 'assessment_created';
    case AssessmentRevisionCreated = 'assessment_revision_created';
    case AssessmentSubmittedForReview = 'assessment_submitted_for_review';
    case AssessmentReturnedToDraft = 'assessment_returned_to_draft';
    case AssessmentPublished = 'assessment_published';
    case AssessmentArchived = 'assessment_archived';
    case AssessmentPublicationCreated = 'assessment_publication_created';
    case AssessmentDeliveryCreated = 'assessment_delivery_created';
    case AssessmentDeliveryDraftUpdated = 'assessment_delivery_draft_updated';
    case AssessmentDeliveryActivated = 'assessment_delivery_activated';
    case AssessmentDeliveryClosed = 'assessment_delivery_closed';
    case AssessmentDeliveryCancelled = 'assessment_delivery_cancelled';
    case AssessmentDeliveryRecipientAdded = 'assessment_delivery_recipient_added';
    case AssessmentDeliveryRecipientRevoked = 'assessment_delivery_recipient_revoked';
    case AssessmentAttemptStarted = 'assessment_attempt_started';
    case AssessmentAttemptAnswerSaved = 'assessment_attempt_answer_saved';
    case AssessmentAttemptSubmitted = 'assessment_attempt_submitted';
    case AssessmentAttemptExpired = 'assessment_attempt_expired';
    case AssessmentAttemptCancelled = 'assessment_attempt_cancelled';
    case AssessmentScoringStarted = 'assessment_scoring_started';
    case AssessmentScoringCompleted = 'assessment_scoring_completed';
    case AssessmentScoringPendingManual = 'assessment_scoring_pending_manual';
    case AssessmentScoringFailed = 'assessment_scoring_failed';
    case AssessmentItemManuallyGraded = 'assessment_item_manually_graded';
    case AssessmentRegraded = 'assessment_regraded';
    case AssessmentResultReleased = 'assessment_result_released';
    case AssessmentResultSuperseded = 'assessment_result_superseded';
    case AssessmentResultWithdrawn = 'assessment_result_withdrawn';
    case AssessmentResultReviewPolicyCreated = 'assessment_result_review_policy_created';
    case AssessmentResultReviewPolicyUpdated = 'assessment_result_review_policy_updated';
    case AssessmentResultReviewPolicyActivated = 'assessment_result_review_policy_activated';
    case AssessmentResultReviewPolicySuperseded = 'assessment_result_review_policy_superseded';
    case AssessmentAnalyticsViewed = 'assessment_analytics_viewed';
    case ClassroomAnalyticsViewed = 'classroom_analytics_viewed';
    case StudentAnalyticsViewed = 'student_analytics_viewed';
    case QuestionAnalyticsViewed = 'question_analytics_viewed';
    case LearningOutcomeAnalyticsViewed = 'learning_outcome_analytics_viewed';
}
