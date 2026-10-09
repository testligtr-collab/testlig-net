<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\InstitutionWorkspaceDecision;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\AcademicYearFailureReason;
use App\Enum\ClassroomFailureReason;
use App\Enum\ClassroomStatus;
use App\Enum\ClassroomStudentFailureReason;
use App\Enum\ClassroomTeacherFailureReason;
use App\Enum\GradeLevel;
use App\Enum\InstitutionStudentInviteFailureReason;
use App\Enum\InstitutionTeacherInviteFailureReason;
use App\Exception\AcademicYearException;
use App\Exception\ClassroomException;
use App\Exception\ClassroomStudentEnrollmentException;
use App\Exception\ClassroomTeacherAssignmentException;
use App\Exception\InstitutionStudentInviteException;
use App\Exception\InstitutionTeacherInviteException;
use App\Service\AcademicYearManager;
use App\Service\InstitutionClassroomEditor;
use App\Service\InstitutionClassroomStudentEditor;
use App\Service\InstitutionClassroomTeacherEditor;
use App\Service\InstitutionStudentInvitationManager;
use App\Service\InstitutionTeacherInvitationManager;
use App\Service\InstitutionWorkspaceGate;
use App\Service\InstitutionWorkspaceQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/kurum')]
#[IsGranted('ROLE_USER')]
final class InstitutionWorkspaceController extends AbstractController
{
    public function __construct(
        private readonly InstitutionWorkspaceGate $gate,
        private readonly InstitutionWorkspaceQuery $query,
        private readonly InstitutionClassroomEditor $classrooms,
        private readonly InstitutionClassroomTeacherEditor $teacherAssignments,
        private readonly InstitutionTeacherInvitationManager $teacherInvites,
        private readonly InstitutionStudentInvitationManager $studentInvites,
        private readonly InstitutionClassroomStudentEditor $studentEnrollments,
        private readonly AcademicYearManager $academicYearManager,
    ) {
    }

    #[Route('', name: 'app_institution_overview', methods: ['GET'])]
    public function overview(): Response
    {
        $user = $this->account();
        $decision = $this->gate->resolve($user);
        $blocked = $this->blocked($user, $decision);
        if ($blocked instanceof Response) {
            return $blocked;
        }
        $selected = $decision->selected;
        if (null === $selected) {
            return $this->render('institution/choose.html.twig', ['options' => $decision->options]);
        }

        return $this->render('institution/overview.html.twig', $this->frame($decision, 'overview', [
            'overview' => $this->query->overview($selected, \count($decision->options) > 1),
        ]));
    }

    #[Route('/siniflar', name: 'app_institution_classrooms', methods: ['GET'])]
    public function classrooms(Request $request): Response
    {
        $opened = $this->open();
        if ($opened instanceof Response) {
            return $opened;
        }
        $selected = $opened->selected;
        if (null === $selected) {
            throw new AccessDeniedHttpException('Kurum seçilmeden bu liste açılamaz.');
        }

        $status = 'arsiv' === (string) $request->query->get('durum') ? ClassroomStatus::Archived : ClassroomStatus::Active;

        return $this->render('institution/classrooms.html.twig', $this->frame($opened, 'classrooms', [
            'classrooms' => $this->query->classrooms($selected->getInstitution(), $this->pageNumber($request), $status),
            'archive_view' => ClassroomStatus::Archived === $status,
        ]));
    }

    #[Route('/siniflar/{reference}', name: 'app_institution_classroom', methods: ['GET'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function classroom(string $reference): Response
    {
        $institution = $this->institution();
        $detail = $this->query->classroom($institution, strtolower($reference));
        if (null === $detail) {
            throw new NotFoundHttpException('Not Found');
        }

        return $this->render('institution/classroom.html.twig', $this->frame($this->gate->resolve($this->account()), 'classrooms', [
            'detail' => $detail,
            'assign_teachers' => $this->teacherAssignments->candidates($this->account(), $institution, strtolower($reference)),
        ]));
    }

    #[Route('/siniflar/yeni', name: 'app_institution_classroom_new', methods: ['GET', 'POST'])]
    public function newClassroom(Request $request): Response
    {
        $opened = $this->open();
        if ($opened instanceof Response) {
            return $opened;
        }
        $selected = $opened->selected;
        if (null === $selected) {
            throw new AccessDeniedHttpException('Kurum seçilmeden bu liste açılamaz.');
        }
        $institution = $selected->getInstitution();
        $years = $this->classrooms->operableYears($institution);
        $values = [
            'name' => '',
            'grade_level' => '',
            'section_code' => '',
            'capacity' => '',
            'year_reference' => '',
        ];
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('institution_classroom', (string) $request->request->get('_token'))) {
                throw new AccessDeniedHttpException('Geçersiz istek.');
            }
            $values = $this->classroomInput($request);
            $grade = $this->grade($values['grade_level']);
            $capacity = $this->capacity($values['capacity']);
            if (!$grade instanceof GradeLevel || false === $capacity) {
                $error = 'Sınıf adı, seviye ve kontenjan alanlarını kontrol edin.';
            } else {
                try {
                    $reference = $this->classrooms->create(
                        $this->account(),
                        $institution,
                        $values['year_reference'],
                        $values['name'],
                        $grade,
                        $values['section_code'],
                        $capacity,
                    );
                    $this->addFlash('success', 'Sınıf oluşturuldu.');

                    return $this->redirectToRoute('app_institution_classroom', ['reference' => $reference]);
                } catch (ClassroomException $exception) {
                    if (ClassroomFailureReason::NotFound === $exception->getReason()) {
                        throw new NotFoundHttpException('Not Found');
                    }
                    $error = $this->classroomError($exception);
                }
            }
        }

        return $this->render('institution/classroom_form.html.twig', $this->frame($opened, 'classrooms', [
            'mode' => 'create',
            'years' => $years,
            'grades' => $this->grades(),
            'values' => $values,
            'error' => $error,
            'classroom' => null,
        ]));
    }

    #[Route('/siniflar/{reference}/duzenle', name: 'app_institution_classroom_edit', methods: ['GET', 'POST'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function editClassroom(Request $request, string $reference): Response
    {
        $institution = $this->institution();
        $detail = $this->query->classroom($institution, strtolower($reference));
        if (null === $detail) {
            throw new NotFoundHttpException('Not Found');
        }
        if (!$detail->classroom->canEdit) {
            throw new AccessDeniedHttpException('Arşivlenmiş sınıf düzenlenemez.');
        }
        $values = [
            'name' => $detail->classroom->name,
            'capacity' => $detail->classroom->capacityLabel ?? '',
            'updated_at' => $detail->classroom->updatedAtToken,
        ];
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('institution_classroom', (string) $request->request->get('_token'))) {
                throw new AccessDeniedHttpException('Geçersiz istek.');
            }
            $values['name'] = trim((string) $request->request->get('name'));
            $values['capacity'] = trim((string) $request->request->get('capacity'));
            $values['updated_at'] = trim((string) $request->request->get('updated_at'));
            $capacity = $this->capacity($values['capacity']);
            if (false === $capacity || '' === $values['name']) {
                $error = 'Sınıf adı ve kontenjan alanlarını kontrol edin.';
            } else {
                try {
                    $this->classrooms->revise(
                        $this->account(),
                        $institution,
                        strtolower($reference),
                        $values['name'],
                        $capacity,
                        $values['updated_at'],
                    );
                    $this->addFlash('success', 'Sınıf güncellendi.');

                    return $this->redirectToRoute('app_institution_classroom', ['reference' => strtolower($reference)]);
                } catch (ClassroomException $exception) {
                    if (ClassroomFailureReason::NotFound === $exception->getReason()) {
                        throw new NotFoundHttpException('Not Found');
                    }
                    $error = $this->classroomError($exception);
                }
            }
        }

        return $this->render('institution/classroom_form.html.twig', $this->frame($this->gate->resolve($this->account()), 'classrooms', [
            'mode' => 'edit',
            'years' => [],
            'grades' => [],
            'values' => $values,
            'error' => $error,
            'classroom' => $detail->classroom,
        ]));
    }

    #[Route('/siniflar/{reference}/arsivle', name: 'app_institution_classroom_archive', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function archiveClassroom(Request $request, string $reference): Response
    {
        $institution = $this->institution();
        if (!$this->isCsrfTokenValid('institution_classroom_archive', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $reference = strtolower($reference);
        try {
            $this->classrooms->archive($this->account(), $institution, $reference);
            $this->addFlash('success', 'Sınıf arşivlendi. Bu işlem geri alınamaz.');
        } catch (ClassroomException $exception) {
            if (ClassroomFailureReason::NotFound === $exception->getReason()) {
                throw new NotFoundHttpException('Not Found');
            }
            $this->addFlash('error', $this->classroomError($exception));
        }

        return $this->redirectToRoute('app_institution_classroom', ['reference' => $reference]);
    }

    #[Route('/ogretmenler', name: 'app_institution_teachers', methods: ['GET'])]
    public function teachers(Request $request): Response
    {
        $institution = $this->institution();

        return $this->render('institution/teachers.html.twig', $this->frame($this->gate->resolve($this->account()), 'teachers', [
            'people' => $this->query->teachers($institution, $this->pageNumber($request)),
            'invites' => $this->query->teacherInvites($institution, 'gecmis' === (string) $request->query->get('durum')),
            'history_view' => 'gecmis' === (string) $request->query->get('durum'),
        ]));
    }

    #[Route('/ogretmenler/davet', name: 'app_institution_teacher_invite', methods: ['GET', 'POST'])]
    public function inviteTeacher(Request $request): Response
    {
        $institution = $this->institution();
        $values = ['email' => '', 'note' => ''];
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('institution_teacher_invite', (string) $request->request->get('_token'))) {
                throw new AccessDeniedHttpException('Geçersiz istek.');
            }
            $values['email'] = trim((string) $request->request->get('email'));
            $values['note'] = trim((string) $request->request->get('note'));
            try {
                $dispatch = $this->teacherInvites->issue($this->account(), $institution, $values['email'], $values['note']);
                $this->teacherInvites->deliver($dispatch);
                $this->addFlash('success', 'Davet gönderildi veya mevcut bekleyen davet güncellendi.');

                return $this->redirectToRoute('app_institution_teachers');
            } catch (InstitutionTeacherInviteException $exception) {
                if (InstitutionTeacherInviteFailureReason::NotFound === $exception->getReason()) {
                    throw new NotFoundHttpException('Not Found');
                }
                $error = $this->inviteError($exception);
            }
        }

        return $this->render('institution/teacher_invite.html.twig', $this->frame($this->gate->resolve($this->account()), 'teachers', [
            'values' => $values,
            'error' => $error,
        ]));
    }

    #[Route('/ogretmenler/davetler/{reference}/yeniden', name: 'app_institution_teacher_invite_resend', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function resendTeacherInvite(Request $request, string $reference): Response
    {
        $institution = $this->institution();
        if (!$this->isCsrfTokenValid('institution_teacher_invite_resend', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        try {
            $dispatch = $this->teacherInvites->resend($this->account(), $institution, strtolower($reference));
            $this->teacherInvites->deliver($dispatch);
            $this->addFlash('success', 'Davet gönderildi veya mevcut bekleyen davet güncellendi.');
        } catch (InstitutionTeacherInviteException $exception) {
            if (InstitutionTeacherInviteFailureReason::NotFound === $exception->getReason()) {
                throw new NotFoundHttpException('Not Found');
            }
            $this->addFlash('error', $this->inviteError($exception));
        }

        return $this->redirectToRoute('app_institution_teachers');
    }

    #[Route('/ogretmenler/davetler/{reference}/iptal', name: 'app_institution_teacher_invite_revoke', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function revokeTeacherInvite(Request $request, string $reference): Response
    {
        $institution = $this->institution();
        if (!$this->isCsrfTokenValid('institution_teacher_invite_revoke', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        try {
            $this->teacherInvites->revoke($this->account(), $institution, strtolower($reference));
            $this->addFlash('success', 'Davet iptal edildi.');
        } catch (InstitutionTeacherInviteException $exception) {
            if (InstitutionTeacherInviteFailureReason::NotFound === $exception->getReason()) {
                throw new NotFoundHttpException('Not Found');
            }
            $this->addFlash('error', $this->inviteError($exception));
        }

        return $this->redirectToRoute('app_institution_teachers');
    }

    #[Route('/siniflar/{reference}/ogretmen', name: 'app_institution_classroom_teacher_assign', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function assignTeacher(Request $request, string $reference): Response
    {
        $institution = $this->institution();
        if (!$this->isCsrfTokenValid('institution_classroom_teacher_assign', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        try {
            $this->teacherAssignments->assign(
                $this->account(),
                $institution,
                strtolower($reference),
                strtolower(trim((string) $request->request->get('membership_reference'))),
                trim((string) $request->request->get('assignment_role')),
            );
            $this->addFlash('success', 'Öğretmen sınıfa atandı.');
        } catch (ClassroomTeacherAssignmentException $exception) {
            if (ClassroomTeacherFailureReason::NotFound === $exception->getReason()
                || ClassroomTeacherFailureReason::CrossInstitution === $exception->getReason()) {
                throw new NotFoundHttpException('Not Found');
            }
            $this->addFlash('error', $this->assignmentError($exception));
        }

        return $this->redirectToRoute('app_institution_classroom', ['reference' => strtolower($reference)]);
    }

    #[Route('/siniflar/{reference}/ogretmen/{assignment}/sonlandir', name: 'app_institution_classroom_teacher_end', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}', 'assignment' => '[0-9a-f]{20}'])]
    public function endTeacherAssignment(Request $request, string $reference, string $assignment): Response
    {
        $institution = $this->institution();
        if (!$this->isCsrfTokenValid('institution_classroom_teacher_end', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        try {
            $this->teacherAssignments->end($this->account(), $institution, strtolower($reference), strtolower($assignment));
            $this->addFlash('success', 'Öğretmen ataması sonlandırıldı. Kayıt silinmedi.');
        } catch (ClassroomTeacherAssignmentException $exception) {
            if (ClassroomTeacherFailureReason::NotFound === $exception->getReason()
                || ClassroomTeacherFailureReason::CrossInstitution === $exception->getReason()) {
                throw new NotFoundHttpException('Not Found');
            }
            $this->addFlash('error', $this->assignmentError($exception));
        }

        return $this->redirectToRoute('app_institution_classroom', ['reference' => strtolower($reference)]);
    }

    #[Route('/siniflar/{reference}/ogrenci-davet', name: 'app_institution_student_invite', methods: ['GET', 'POST'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function inviteStudent(Request $request, string $reference): Response
    {
        $institution = $this->institution();
        $reference = strtolower($reference);
        if (null === $this->query->classroom($institution, $reference)) {
            throw new NotFoundHttpException('Not Found');
        }
        $values = ['email' => '', 'note' => ''];
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('institution_student_invite', (string) $request->request->get('_token'))) {
                throw new AccessDeniedHttpException('Geçersiz istek.');
            }
            $values['email'] = trim((string) $request->request->get('email'));
            $values['note'] = trim((string) $request->request->get('note'));
            try {
                $dispatch = $this->studentInvites->issue($this->account(), $institution, $reference, $values['email'], $values['note']);
                $this->studentInvites->deliver($dispatch);
                $this->addFlash('success', 'Davet gönderildi veya mevcut bekleyen davet güncellendi.');

                return $this->redirectToRoute('app_institution_classroom', ['reference' => $reference]);
            } catch (InstitutionStudentInviteException $exception) {
                if (InstitutionStudentInviteFailureReason::NotFound === $exception->getReason()
                    || InstitutionStudentInviteFailureReason::Unauthorized === $exception->getReason()) {
                    throw new NotFoundHttpException('Not Found');
                }
                $error = $this->studentInviteError($exception);
            }
        }

        return $this->render('institution/student_invite.html.twig', $this->frame($this->gate->resolve($this->account()), 'classrooms', [
            'values' => $values,
            'error' => $error,
            'classroom_reference' => $reference,
        ]));
    }

    #[Route('/siniflar/{reference}/ogrenci-davetler/{invite}/yeniden', name: 'app_institution_student_invite_resend', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}', 'invite' => '[0-9a-f]{20}'])]
    public function resendStudentInvite(Request $request, string $reference, string $invite): Response
    {
        $institution = $this->institution();
        $reference = strtolower($reference);
        if (!$this->isCsrfTokenValid('institution_student_invite_resend', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        try {
            $dispatch = $this->studentInvites->resend($this->account(), $institution, $reference, strtolower($invite));
            $this->studentInvites->deliver($dispatch);
            $this->addFlash('success', 'Davet gönderildi veya mevcut bekleyen davet güncellendi.');
        } catch (InstitutionStudentInviteException $exception) {
            if (InstitutionStudentInviteFailureReason::NotFound === $exception->getReason()
                || InstitutionStudentInviteFailureReason::Unauthorized === $exception->getReason()) {
                throw new NotFoundHttpException('Not Found');
            }
            $this->addFlash('error', $this->studentInviteError($exception));
        }

        return $this->redirectToRoute('app_institution_classroom', ['reference' => $reference]);
    }

    #[Route('/siniflar/{reference}/ogrenci-davetler/{invite}/iptal', name: 'app_institution_student_invite_revoke', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}', 'invite' => '[0-9a-f]{20}'])]
    public function revokeStudentInvite(Request $request, string $reference, string $invite): Response
    {
        $institution = $this->institution();
        $reference = strtolower($reference);
        if (!$this->isCsrfTokenValid('institution_student_invite_revoke', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        try {
            $this->studentInvites->revoke($this->account(), $institution, $reference, strtolower($invite));
            $this->addFlash('success', 'Öğrenci daveti iptal edildi.');
        } catch (InstitutionStudentInviteException $exception) {
            if (InstitutionStudentInviteFailureReason::NotFound === $exception->getReason()
                || InstitutionStudentInviteFailureReason::Unauthorized === $exception->getReason()) {
                throw new NotFoundHttpException('Not Found');
            }
            $this->addFlash('error', $this->studentInviteError($exception));
        }

        return $this->redirectToRoute('app_institution_classroom', ['reference' => $reference]);
    }

    #[Route('/siniflar/{reference}/ogrenci/{enrollment}/sonlandir', name: 'app_institution_student_enrollment_end', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}', 'enrollment' => '[0-9a-f]{20}'])]
    public function endStudentEnrollment(Request $request, string $reference, string $enrollment): Response
    {
        $institution = $this->institution();
        $reference = strtolower($reference);
        if (!$this->isCsrfTokenValid('institution_student_enrollment_end', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        try {
            $this->studentEnrollments->end($this->account(), $institution, $reference, strtolower($enrollment));
            $this->addFlash('success', 'Öğrenci kaydı sonlandırıldı. Geçmiş kayıt silinmedi.');
        } catch (ClassroomStudentEnrollmentException $exception) {
            if (ClassroomStudentFailureReason::NotFound === $exception->getReason()
                || ClassroomStudentFailureReason::CrossInstitution === $exception->getReason()
                || ClassroomStudentFailureReason::Unauthorized === $exception->getReason()) {
                throw new NotFoundHttpException('Not Found');
            }
            $this->addFlash('error', $this->enrollmentError($exception));
        }

        return $this->redirectToRoute('app_institution_classroom', ['reference' => $reference]);
    }

    #[Route('/siniflar/{reference}/ogrenci/{enrollment}/aktar', name: 'app_institution_student_enrollment_transfer', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}', 'enrollment' => '[0-9a-f]{20}'])]
    public function transferStudentEnrollment(Request $request, string $reference, string $enrollment): Response
    {
        $institution = $this->institution();
        $reference = strtolower($reference);
        if (!$this->isCsrfTokenValid('institution_student_enrollment_transfer', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        try {
            $this->studentEnrollments->transfer(
                $this->account(),
                $institution,
                $reference,
                strtolower($enrollment),
                strtolower(trim((string) $request->request->get('target_reference'))),
            );
            $this->addFlash('success', 'Öğrenci aynı dönem içinde başka sınıfa aktarıldı. Eski kayıt silinmedi.');
        } catch (ClassroomStudentEnrollmentException $exception) {
            if (ClassroomStudentFailureReason::NotFound === $exception->getReason()
                || ClassroomStudentFailureReason::CrossInstitution === $exception->getReason()
                || ClassroomStudentFailureReason::Unauthorized === $exception->getReason()) {
                throw new NotFoundHttpException('Not Found');
            }
            $this->addFlash('error', $this->enrollmentError($exception));
        }

        return $this->redirectToRoute('app_institution_classroom', ['reference' => $reference]);
    }

    #[Route('/ogrenciler', name: 'app_institution_students', methods: ['GET'])]
    public function students(Request $request): Response
    {
        $institution = $this->institution();

        return $this->render('institution/people.html.twig', $this->frame($this->gate->resolve($this->account()), 'students', [
            'heading' => 'Öğrenciler',
            'empty_message' => 'Henüz öğrenci yok.',
            'people' => $this->query->students($institution, $this->pageNumber($request)),
            'show_classrooms' => false,
            'show_grade' => true,
        ]));
    }

    #[Route('/testler', name: 'app_institution_tests', methods: ['GET'])]
    public function tests(Request $request): Response
    {
        $institution = $this->institution();

        return $this->render('institution/tests.html.twig', $this->frame($this->gate->resolve($this->account()), 'tests', [
            'tests' => $this->query->tests($institution, $this->pageNumber($request)),
        ]));
    }

    #[Route('/testler/{reference}', name: 'app_institution_test', methods: ['GET'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function test(string $reference): Response
    {
        $institution = $this->institution();
        $row = $this->query->test($institution, strtolower($reference));
        if (null === $row) {
            throw new NotFoundHttpException('Not Found');
        }

        return $this->render('institution/test.html.twig', $this->frame($this->gate->resolve($this->account()), 'tests', [
            'test' => $row,
        ]));
    }

    #[Route('/akademik-yillar', name: 'app_institution_academic_years', methods: ['GET', 'POST'])]
    public function academicYears(Request $request): Response
    {
        $institution = $this->institution();
        $values = ['name' => '', 'starts_on' => '', 'ends_on' => ''];
        if (!$request->isMethod('POST')) {
            return $this->yearPage($institution, null, $values);
        }
        if (!$this->isCsrfTokenValid('institution_year_create', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }

        $values = [
            'name' => trim((string) $request->request->get('name', '')),
            'starts_on' => trim((string) $request->request->get('starts_on', '')),
            'ends_on' => trim((string) $request->request->get('ends_on', '')),
        ];
        $starts = self::calendarDate($values['starts_on']);
        $ends = self::calendarDate($values['ends_on']);
        if (!$starts instanceof \DateTimeImmutable || !$ends instanceof \DateTimeImmutable) {
            return $this->yearPage($institution, 'Başlangıç ve bitiş tarihleri geçerli olmalıdır.', $values);
        }

        try {
            $this->academicYearManager->createPlanned(
                $institution,
                $this->account(),
                $values['name'],
                $starts,
                $ends,
                'panel_year_create',
            );
        } catch (AcademicYearException $exception) {
            return $this->presentYearFailure($institution, $exception, $values);
        }

        return $this->redirectToRoute('app_institution_academic_years');
    }

    #[Route('/akademik-yillar/{reference}/aktiflestir', name: 'app_institution_academic_year_activate', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function activateAcademicYear(Request $request, string $reference): Response
    {
        $institution = $this->institution();
        if (!$this->isCsrfTokenValid('institution_year_activate', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $year = $this->query->academicYear($institution, strtolower($reference));
        if (null === $year) {
            throw new NotFoundHttpException('Not Found');
        }
        $values = ['name' => '', 'starts_on' => '', 'ends_on' => ''];
        if ('1' !== (string) $request->request->get('confirm')) {
            return $this->yearPage($institution, 'Dönemi aktifleştirmek için onay kutusunu işaretleyin.', $values);
        }

        try {
            $this->academicYearManager->activate($year, $this->account(), 'panel_year_activate');
        } catch (AcademicYearException $exception) {
            return $this->presentYearFailure($institution, $exception, $values);
        }

        return $this->redirectToRoute('app_institution_academic_years');
    }

    #[Route('/baglam', name: 'app_institution_context', methods: ['POST'])]
    public function context(Request $request): Response
    {
        $user = $this->account();
        if (!$this->isCsrfTokenValid('institution_context', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $decision = $this->gate->resolve($user);
        if (InstitutionWorkspaceGate::DENIED === $decision->outcome || InstitutionWorkspaceGate::ONBOARDING === $decision->outcome) {
            throw new NotFoundHttpException('Not Found');
        }
        if (!$this->gate->select($user, (string) $request->request->get('reference'))) {
            throw new NotFoundHttpException('Not Found');
        }

        return $this->redirectToRoute('app_institution_overview');
    }

    private function blocked(User $user, InstitutionWorkspaceDecision $decision): ?Response
    {
        if (InstitutionWorkspaceGate::DENIED === $decision->outcome) {
            throw new AccessDeniedHttpException('Bu sayfaya erişilemiyor.');
        }
        if (InstitutionWorkspaceGate::ONBOARDING === $decision->outcome) {
            return $this->render('institution/onboarding.html.twig', [
                'application_message' => $this->query->applicationMessage($user),
            ]);
        }

        return null;
    }

    private function open(): InstitutionWorkspaceDecision|Response
    {
        $user = $this->account();
        $decision = $this->gate->resolve($user);
        $blocked = $this->blocked($user, $decision);
        if ($blocked instanceof Response) {
            return $blocked;
        }
        if (null === $decision->selected) {
            return $this->redirectToRoute('app_institution_overview');
        }

        return $decision;
    }

    private function institution(): Institution
    {
        $opened = $this->open();
        if ($opened instanceof Response) {
            throw new AccessDeniedHttpException('Kurum seçilmeden bu liste açılamaz.');
        }
        $selected = $opened->selected;
        if (null === $selected) {
            throw new AccessDeniedHttpException('Kurum seçilmeden bu liste açılamaz.');
        }

        return $selected->getInstitution();
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function frame(InstitutionWorkspaceDecision $decision, string $nav, array $extra): array
    {
        $selected = $decision->selected;
        if (null === $selected) {
            throw new AccessDeniedHttpException('Bu sayfaya erişilemiyor.');
        }

        return $extra + [
            'nav' => $nav,
            'institution_name' => $selected->getInstitution()->getName(),
            'role_label' => InstitutionWorkspaceGate::roleLabel($selected->getRole()->value),
            'can_switch' => \count($decision->options) > 1,
            'options' => $decision->options,
        ];
    }

    /**
     * @param array{name: string, starts_on: string, ends_on: string} $values
     */
    private function yearPage(Institution $institution, ?string $error, array $values): Response
    {
        return $this->render('institution/academic_years.html.twig', $this->frame($this->gate->resolve($this->account()), 'classrooms', [
            'years' => $this->query->academicYears($institution),
            'error' => $error,
            'values' => $values,
        ]));
    }

    /**
     * @param array{name: string, starts_on: string, ends_on: string} $values
     */
    private function presentYearFailure(Institution $institution, AcademicYearException $exception, array $values): Response
    {
        if (AcademicYearFailureReason::InstitutionNotOperable === $exception->getReason()) {
            throw new AccessDeniedHttpException('Kurum bu işlem için uygun değil.');
        }
        $error = match ($exception->getReason()) {
            AcademicYearFailureReason::InvalidInput => 'Dönem adı ve tarihleri geçerli olmalıdır. Bitiş, başlangıçtan önce olamaz.',
            AcademicYearFailureReason::Conflict => 'Bu adla bir dönem zaten var.',
            AcademicYearFailureReason::DateOverlap => 'Bu tarihler kurumdaki başka bir dönemle çakışıyor.',
            AcademicYearFailureReason::InvalidTransition, AcademicYearFailureReason::YearNotOperable => 'Bu dönem aktifleştirilemez.',
            default => null,
        };
        if (null === $error) {
            throw new NotFoundHttpException('Not Found');
        }

        return $this->yearPage($institution, $error, $values);
    }

    private static function calendarDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date instanceof \DateTimeImmutable || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }

    private function pageNumber(Request $request): int
    {
        return max(1, min(50, (int) $request->query->get('sayfa', '1')));
    }

    /**
     * @return array{name: string, grade_level: string, section_code: string, capacity: string, year_reference: string}
     */
    private function classroomInput(Request $request): array
    {
        return [
            'name' => trim((string) $request->request->get('name')),
            'grade_level' => trim((string) $request->request->get('grade_level')),
            'section_code' => trim((string) $request->request->get('section_code')),
            'capacity' => trim((string) $request->request->get('capacity')),
            'year_reference' => strtolower(trim((string) $request->request->get('year_reference'))),
        ];
    }

    private function grade(string $value): ?GradeLevel
    {
        if (1 !== preg_match('/^\d{1,2}$/', $value)) {
            return null;
        }

        return GradeLevel::tryFrom((int) $value);
    }

    private function capacity(string $value): int|false|null
    {
        if ('' === $value) {
            return null;
        }
        if (1 !== preg_match('/^\d{1,3}$/', $value)) {
            return false;
        }

        return (int) $value;
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function grades(): array
    {
        $grades = [];
        foreach (GradeLevel::cases() as $grade) {
            $grades[] = ['value' => $grade->value, 'label' => $grade->value.'. sınıf'];
        }

        return $grades;
    }

    private function classroomError(ClassroomException $exception): string
    {
        return match ($exception->getReason()) {
            ClassroomFailureReason::Conflict => 'Sınıf kaydedilemedi. Aynı dönemde bu ad kullanılıyor olabilir veya kayıt siz formu açtıktan sonra değişmiş.',
            ClassroomFailureReason::ClassroomNotOperable => 'Arşivlenmiş sınıf düzenlenemez.',
            ClassroomFailureReason::InvalidTransition => 'Bu sınıf arşivlenemez.',
            ClassroomFailureReason::YearNotOperable => 'Seçilen eğitim döneminde sınıf işlemi yapılamaz.',
            ClassroomFailureReason::InstitutionNotOperable => 'Bu kurum durumunda sınıf işlemi yapılamaz.',
            ClassroomFailureReason::CapacityBelowEnrollment => 'Kontenjan, sınıftaki öğrenci sayısından küçük olamaz.',
            ClassroomFailureReason::InvalidInput => 'Girilen sınıf bilgisi geçerli değil.',
            ClassroomFailureReason::Unauthorized => 'Bu işlem için yetkiniz yok.',
            ClassroomFailureReason::NotFound, ClassroomFailureReason::CrossInstitution => 'Not Found',
        };
    }

    private function studentInviteError(InstitutionStudentInviteException $exception): string
    {
        return match ($exception->getReason()) {
            InstitutionStudentInviteFailureReason::InvalidInput => 'E-posta veya davet notu geçerli değil.',
            InstitutionStudentInviteFailureReason::NotEligible => 'Bu kişi bu kurumda öğrenci olarak kaydedilemez.',
            InstitutionStudentInviteFailureReason::GradeMismatch => 'Öğrencinin sınıf seviyesi bu sınıfla uyuşmuyor.',
            InstitutionStudentInviteFailureReason::Capacity => 'Sınıf kontenjanı dolu.',
            InstitutionStudentInviteFailureReason::Unavailable => 'Sınıf veya eğitim dönemi şu anda davete açık değil.',
            InstitutionStudentInviteFailureReason::RateLimited => 'Çok fazla davet denemesi. Bir süre sonra yeniden deneyin.',
            InstitutionStudentInviteFailureReason::MailFailed => 'Davet kaydedildi ancak e-posta gönderilemedi. Yeniden gönderebilirsiniz.',
            InstitutionStudentInviteFailureReason::Conflict => 'Davet kaydedilemedi. Yeniden deneyin.',
            InstitutionStudentInviteFailureReason::Unauthorized,
            InstitutionStudentInviteFailureReason::NotFound,
            InstitutionStudentInviteFailureReason::AccountMismatch,
            InstitutionStudentInviteFailureReason::AccountNotReady,
            InstitutionStudentInviteFailureReason::ProfileNotReady => 'Davet işlemi tamamlanamadı.',
        };
    }

    private function enrollmentError(ClassroomStudentEnrollmentException $exception): string
    {
        return match ($exception->getReason()) {
            ClassroomStudentFailureReason::InvalidInput => 'Öğrencinin sınıf seviyesi hedef sınıfla uyuşmuyor.',
            ClassroomStudentFailureReason::CapacityExceeded => 'Hedef sınıfın kontenjanı dolu.',
            ClassroomStudentFailureReason::YearNotOperable => 'Kapalı eğitim döneminde sınıf değiştirilemez.',
            ClassroomStudentFailureReason::ClassroomNotOperable => 'Hedef sınıf aktif değil.',
            ClassroomStudentFailureReason::InstitutionNotOperable => 'Bu kurum durumunda kayıt değiştirilemez.',
            ClassroomStudentFailureReason::InvalidTransition => 'Bu kayıt sonlandırılamaz.',
            ClassroomStudentFailureReason::Conflict => 'Kayıt işlemi tamamlanamadı. Yeniden deneyin.',
            ClassroomStudentFailureReason::MembershipNotEligible => 'Öğrenci kaydı bu üyelikle yapılamaz.',
            ClassroomStudentFailureReason::Unauthorized,
            ClassroomStudentFailureReason::NotFound,
            ClassroomStudentFailureReason::CrossInstitution => 'Kayıt işlemi tamamlanamadı.',
        };
    }

    private function inviteError(InstitutionTeacherInviteException $exception): string
    {
        return match ($exception->getReason()) {
            InstitutionTeacherInviteFailureReason::InvalidInput => 'E-posta veya davet notu geçerli değil.',
            InstitutionTeacherInviteFailureReason::AlreadyTeacher => 'Bu kurumda bu e-posta ile aktif bir öğretmen üyeliği var.',
            InstitutionTeacherInviteFailureReason::NotEligible => 'Bu kişi bu kuruma öğretmen olarak davet edilemez.',
            InstitutionTeacherInviteFailureReason::RateLimited => 'Çok fazla davet denemesi. Bir süre sonra yeniden deneyin.',
            InstitutionTeacherInviteFailureReason::MailFailed => 'Davet kaydedildi ancak e-posta gönderilemedi. Yeniden gönderebilirsiniz.',
            InstitutionTeacherInviteFailureReason::Unavailable => 'Bu davet yeniden gönderilemez veya iptal edilemez.',
            InstitutionTeacherInviteFailureReason::Unauthorized => 'Bu işlem için yetkiniz yok.',
            InstitutionTeacherInviteFailureReason::Conflict => 'Davet kaydedilemedi. Yeniden deneyin.',
            InstitutionTeacherInviteFailureReason::NotFound,
            InstitutionTeacherInviteFailureReason::AccountMismatch,
            InstitutionTeacherInviteFailureReason::AccountNotReady => 'Davet işlemi tamamlanamadı.',
        };
    }

    private function assignmentError(ClassroomTeacherAssignmentException $exception): string
    {
        return match ($exception->getReason()) {
            ClassroomTeacherFailureReason::Conflict => 'Bu atama yapılamadı. Öğretmen zaten atanmış olabilir veya sınıfta bir sınıf öğretmeni vardır.',
            ClassroomTeacherFailureReason::ClassroomNotOperable => 'Arşivlenmiş sınıfa öğretmen atanamaz.',
            ClassroomTeacherFailureReason::YearNotOperable => 'Kapalı eğitim döneminde öğretmen atanamaz.',
            ClassroomTeacherFailureReason::MembershipNotEligible => 'Yalnız bu kurumun aktif öğretmeni atanabilir.',
            ClassroomTeacherFailureReason::InstitutionNotOperable => 'Bu kurum durumunda atama yapılamaz.',
            ClassroomTeacherFailureReason::InvalidTransition => 'Bu atama sonlandırılamaz.',
            ClassroomTeacherFailureReason::InvalidInput => 'Atama bilgisi geçerli değil.',
            ClassroomTeacherFailureReason::Unauthorized => 'Bu işlem için yetkiniz yok.',
            ClassroomTeacherFailureReason::NotFound, ClassroomTeacherFailureReason::CrossInstitution => 'Not Found',
        };
    }

    private function account(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Bu sayfaya erişilemiyor.');
        }

        return $user;
    }
}
