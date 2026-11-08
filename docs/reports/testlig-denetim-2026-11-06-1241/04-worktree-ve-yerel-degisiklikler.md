# Worktree ve yerel değişiklikler

Okuma: 2026-11-06 12:41 +03. `git worktree list` + her ağaçta `git status --porcelain`. Checkout/fetch yapılmadı.

## Bu sohbetin çalışma yüzeyi

Cursor workspace: `C:\xampp\htdocs\testlig-net-question-editor-layout`.

| Alan | Değer |
|---|---|
| Toplevel | `C:/xampp/htdocs/testlig-net-question-editor-layout` |
| Dal | detached HEAD |
| HEAD | `fceeefd9c4ba43b356d632e9f04590003090574a` |
| Durum | staged 4, unstaged 0, untracked 0 |
| Staged | `docs/reports/testlig-chatgpt-inceleme-paketi-2026-11-01.md`, `testlig-devam-2026-11-02-x01-homework.md`, `testlig-proje-gecmisi-ekler-2026-11-01.md`, `testlig-proje-gecmisi-ve-mevcut-durum-2026-11-01.md` |

Bu dört dosya bu denetimde değiştirilmedi. Bu klasör (`testlig-denetim-2026-11-06-1241`) yeni ve commit edilmedi. `fceeefd` main değildir; bu ağaçtaki `docs/` güncel ürün kaydı değildir.

## Remote ve main

- `origin`: `https://github.com/testligtr-collab/testlig-net.git` (kimlik bilgisi yok).
- Yerel `origin/main`: `b09f51bbf9e75ae67e65d9632d1ac1e914d573c5`.
- GitHub `main` API: aynı SHA, 2026-11-05T11:56:34Z, mesaj PR #195 merge.

## Birincil ağaç

`C:/xampp/htdocs/testlig-net` dal `feature/m12-admin-test-detail`, HEAD `fceeefd9c4ba` (layout ile aynı commit). Untracked: `compose.override.yaml`, `docker/php/php.local.ini`, ve adı `;` olan bir untracked girdi. Bunlara dokunulmadı. Dal adı güncel işi göstermez; HEAD placement arşiv kontrolüne aittir ve main’in gerisindedir.

## Özellikle istenen ağaçlar

| Yol | Dal | HEAD | PR | Kirli | Not |
|---|---|---|---|---|---|
| question-editor-layout | detached | `fceeefd9c4ba` | yok (eski) | staged 4 rapor | Koru |
| x01-homework-student-submit | `feature/x01-homework-student-submit` | `92bbd52c56a0` | #193 merged | temiz | Tarihsel |
| x01-homework-teacher-evaluate | `chore/x01-195-merge-evidence` | `54df67866abe` | #196 OPEN | temiz | Kanıt PR |
| ci-browser-server-log | `ci/browser-server-log-artifact` | `041ca554cb11` | #197 OPEN | temiz | Tanı PR, main’de değil |
| institution-applications | `feature/m01-admin-institution-applications` | `0578f131fafc` | #149 değil | temiz | `feature/institution-from-application` adlı worktree yok |

`feature/institution-from-application` worktree listesinde yok. Doğrulanamadı; yok sayıldı.

## Diğer worktree’ler

Aşağıdakiler bu okumada staged=0, unstaged=0, untracked=0. Origin senkronu fetch yapılmadığı için “güncel” denmedi. Çoğu merge edilmiş eski feature dalıdır; HEAD main’i izlemez.

| Yol | Dal | HEAD (12) |
|---|---|---|
| testlig-net-admin-academic-years | feature/m06-admin-academic-years | e0238a00e9a1 |
| testlig-net-admin-branches | feature/m05-admin-branches | 8054c1c988c6 |
| testlig-net-admin-classrooms | feature/m06-admin-classrooms | 3abea6c2089a |
| testlig-net-admin-curriculum | feature/m10-admin-curriculum | f6de3da738b5 |
| testlig-net-admin-institution-visual | feature/m04-admin-institution-visual | 366c7b66cd58 |
| testlig-net-admin-institutions | feature/m04-admin-institutions | c496729cc1da |
| testlig-net-admin-media | feature/x10-admin-media-cards | 8e291011029a |
| testlig-net-admin-nav | feature/x10-admin-navigation | 12ba9dff3857 |
| testlig-net-admin-overview | feature/x10-admin-overview | e64f09eea9ca |
| testlig-net-admin-permissions | feature/m03-module-permission-layout | 7e755789b09e |
| testlig-net-admin-section-cards | feature/x10-admin-section-cards | fb382fe56b04 |
| testlig-net-admin-sections | feature/x10-admin-section-directory | 49eff103d792 |
| testlig-net-admin-teachers | feature/m07-admin-teachers | 0bcf52186fbb |
| testlig-net-admin-test-preview | feature/m12-admin-test-preview | fc9f265f5635 |
| testlig-net-admin-test-results | feature/m12-admin-test-results | b112a702af69 |
| testlig-net-admin-user-detail | feature/m03-admin-user-detail | 7a1df93a61aa |
| testlig-net-admin-user-rows | feature/m03-admin-user-row-line | 4b8a54c1684d |
| testlig-net-admin-users | feature/m03-admin-user-list | 8b295e123582 |
| testlig-net-curriculum-program-card | feature/x10-curriculum-program-card | 809974622c2e |
| testlig-net-curriculum-screens | feature/m10-curriculum-screen-clarity | c35e245856b6 |
| testlig-net-curriculum-workspace | feature/m10-curriculum-workspace | ac9c74880cfb |
| testlig-net-learning-content | feature/x10-learning-content-screens | 17b09047ba28 |
| testlig-net-learning-content-layout | feature/x10-learning-content-detail-layout | 75904e53e203 |
| testlig-net-learning-content-polish | feature/x10-learning-content-preview-list | 1a8132019e4f |
| testlig-net-news | feature/m27-news | c70638f13e66 |
| testlig-net-ols-refresh | feature/x06-ols-refresh | f7626acd845d |
| testlig-net-php-inventory | feature/x11-lsphp83-security-apply | 793ec53acda4 |
| testlig-net-question-bank | feature/x10-question-bank-screens | 1687b98cd634 |
| testlig-net-question-editor | feature/m11-question-rich-editor | 1e248dfd8660 |
| testlig-net-teacher-applications | feature/m01-admin-teacher-applications | 2e8439286927 |
| testlig-net-utc-dst | fix/utc-dst-fixed-instants | 6d5b156d1ae3 |
| testlig-net-x01-homework-architecture | docs/x01-homework-architecture | 223f7a365edf |
| testlig-net-x01-homework-assign | feature/x01-homework-assign | 138d1cf097dd |
| testlig-net-x01-homework-draft | feature/x01-homework-draft | 456325e9d207 |
| testlig-net-x01-homework-eval-decision | docs/x01-homework-evaluation-access | 8a6039c6f4d9 |

Alt çubuk veya sohbet başlığı bu tabloya göre düzeltilmedi; git çıktısı esas alındı.
