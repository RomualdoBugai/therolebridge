<?php

// Valida se é bot antes de qualquer coisa (proteção básica)
require_once __DIR__ . '/includes/bot_check_v2.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/provider_jobs.php';

// -----------------------------------------------------
// Flags de contexto
// -----------------------------------------------------

// Tem busca válida? (pelo menos keyword OU location preenchido)
$hasValidSearch = (
    isset($keyword, $location) &&
    (
        trim((string) $keyword) !== '' ||
        trim((string) $location) !== ''
    )
);

// Detecta se veio de email (pra mostrar aviso/branding)
$isFromEmail =
    (isset($_GET['utm_source']) && $_GET['utm_source'] === 'email') ||
    (isset($_GET['from']) && $_GET['from'] === 'email');

// -----------------------------------------------------
// Paginação básica
// -----------------------------------------------------
$page = isset($_GET['page']) ? (int) $_GET['page'] : 0;
if ($page < 0) {
    $page = 0;
}

// Tamanho da página (jobs por página)
$perPage = 9;

// -----------------------------------------------------
// Busca unificada de vagas
// -----------------------------------------------------
// Defaults seguros
$jobs        = [];
$error       = false;
$meta        = [];
$totalFetched = 0;
$jobsToShow  = [];
$shownCount  = 0;
$hasNextPage = false;

// Só chama a API se tiver busca válida (jobkeyword ou location)
if ($hasValidSearch) {
    $response = fetchUnifiedJobs($provider, $email, $keyword, $location, $state, $city, $page, $perPage);

    $jobs   = $response['jobs'] ?? [];
    $error  = (bool) ($response['error'] ?? false);
    $meta   = $response['meta'] ?? [];

    // Total retornado nessa página
    $totalFetched = is_array($jobs) ? count($jobs) : 0;

    // Aqui NÃO precisa de slice: já pedimos $perPage para a API
    $jobsToShow = $jobs;
    $shownCount = $totalFetched;

    // -----------------------------------------------------
    // Cálculo de hasNextPage
    // -----------------------------------------------------
    //
    // 1) Preferimos usar meta['total'] se existir (Talroo).
    // 2) Se não existir meta ou estiver vazio, caímos no fallback simples: 
    //    se trouxe exatamente $perPage, assumimos que PODE haver próxima página.
    //
    $hasNextPage = false;

    if (!$error) {
        if (!empty($meta['total'])) {
            $total     = (int) $meta['total'];
            $offsetEnd = $page * $perPage;
            $hasNextPage = $offsetEnd < $total;
        } else {
            // Fallback genérico
            $hasNextPage = $totalFetched >= $perPage;
        }
    }
}

// -----------------------------------------------------
// URLs de paginação
// -----------------------------------------------------
$prevPageUrl = null;
$nextPageUrl = null;

if ($page > 0) {
    $prevParams  = array_merge($forwardParams, ['page' => $page - 1]);
    $prevPageUrl = '?' . http_build_query($prevParams) . '#job-results';
}

if ($hasNextPage) {
    $nextParams  = array_merge($forwardParams, ['page' => $page + 1]);
    $nextPageUrl = '?' . http_build_query($nextParams) . '#job-results';
}

// -----------------------------------------------------
// Hero title
// -----------------------------------------------------
if (!$hasValidSearch) {
    // Nenhuma busca feita ainda
    $heroTitle = 'Find job leads across the U.S.';
} else {
    $heroTitle = sprintf(
        'Jobs for "%s" in "%s"',
        htmlspecialchars($keyword, ENT_QUOTES),
        htmlspecialchars($location, ENT_QUOTES)
    );
}

$providerConfig = getJobProviderConfig($provider);
$jobPartnerName = $providerConfig['name'] ?? 'our job partner';


// -----------------------------------------------------
// Modal pós-click
// -----------------------------------------------------
// Regras:
// - Abre somente quando vier job_click_id na URL.
// - Primeira abertura mostra a segunda vaga da lista: index 1.
// - Depois do clique no botão Apply / View job, recarrega a página com job_modal_step=3.
// - Com job_modal_step=3, mostra a terceira vaga da lista: index 2.
// - No segundo clique no botão, apenas fecha o modal.
$jobClickId = isset($_GET['job_click_id']) ? trim((string) $_GET['job_click_id']) : '';

$modalStep = isset($_GET['job_modal_step']) ? (int) $_GET['job_modal_step'] : 2;
if (!in_array($modalStep, [2, 3], true)) {
    $modalStep = 2;
}

$modalJobIndex = ($modalStep === 3) ? 2 : 1;
$modalJob = null;

if (
    $jobClickId !== '' &&
    !$error &&
    !empty($jobsToShow) &&
    is_array($jobsToShow) &&
    isset($jobsToShow[$modalJobIndex])
) {
    $jobForModal = $jobsToShow[$modalJobIndex];

    $modalJobTitle    = $jobForModal['title'] ?? 'Job Opportunity';
    $modalJobCompany  = $jobForModal['company'] ?? 'Company';
    $modalJobLocation = $jobForModal['location_label'] ?? '';
    $modalJobSnippet  = cleanSnippet($jobForModal['snippet'] ?? '', 500);
    $modalJobPosted   = formatJobDate($jobForModal['posted_at'] ?? null);
    $modalJobLogoUrl  = $jobForModal['logo_url'] ?? null;

    if (!empty($jobForModal['url'])) {
        $modalJobLink = 'jobs-out.php' . $linkCompleteHref . '&' . http_build_query([
            'click_source' => 'job_list_modal',
            'job_url'      => $jobForModal['url'],
            'job_id'       => $jobForModal['external_id'] ?? '',
            'job_price'    => $jobForModal['score'] ?? '',
        ]);

    } else {
        $modalJobLink = '#';
    }

    $modalJob = [
        'title'    => $modalJobTitle,
        'company'  => $modalJobCompany,
        'location' => $modalJobLocation,
        'snippet'  => $modalJobSnippet,
        'posted'   => $modalJobPosted,
        'logo_url' => $modalJobLogoUrl,
        'link'     => $modalJobLink,
        'step'     => $modalStep,
    ];
}

?>
<!DOCTYPE html>
<html lang="en" class="light scroll-smooth" dir="ltr">

<?php include __DIR__ . '/partials/head.php'; ?>

<body class="font-nunito text-base text-slate-900 dark:text-white dark:bg-slate-900">

    <?php include __DIR__ . '/partials/navbar.php'; ?>

    <!-- Start Hero -->
    <section class="relative table w-full py-16 lg:py-12 bg-[url('../../assets/images/job/job.jpg')] bg-no-repeat bg-cover">
        <div class="absolute inset-0 bg-slate-900 opacity-80"></div>
        <div class="container relative">
            <div class="grid grid-cols-1 pb-8 text-center mt-10">
                <h1 class="mb-4 md:text-4xl text-3xl md:leading-normal leading-normal font-semibold text-white">
                    <?php echo $heroTitle; ?>
                </h1>

                <p class="text-slate-200 max-w-2xl mx-auto">
                    These results are pulled in real-time from
                    <strong><?php echo htmlspecialchars($jobPartnerName, ENT_QUOTES); ?></strong>
                    and may redirect you to external sites to apply.
                    All opportunities are job listings only – <?= $siteTitle ?> is not the hiring company.
                </p>
            </div><!--end grid-->
        </div><!--end container-->
    </section><!--end section-->
    <div class="relative">
        <div class="shape absolute sm:-bottom-px -bottom-0.5 start-0 end-0 overflow-hidden text-white dark:text-slate-900">
            <svg class="w-full h-auto scale-[2.0] origin-top" viewBox="0 0 2880 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M0 48H1437.5H2880V0H2160C1442.5 52 720 0 720 0H0V48Z" fill="currentColor"></path>
            </svg>
        </div>
    </div>
    <!-- End Hero -->

    <!-- Start Section-->
    <section class="relative md:py-24 py-16">
        <div class="container relative">
            <div class="grid lg:grid-cols-12 grid-cols-1" id="reserve-form">
                <div class="lg:col-start-2 lg:col-span-10">
                    <div class="bg-white dark:bg-slate-900 border-0 shadow-sm dark:shadow-gray-800 rounded p-3 -mt-35">
                        <?php if ($isFromEmail): ?>
                            <div class="mb-4 rounded-md border border-primary/30 bg-primary/5 px-4 py-3 text-xs md:text-sm text-slate-700 dark:text-slate-100">
                                You accessed this page from our daily email. The jobs below are similar to the leads we send to your inbox.
                                Check your email again tomorrow for fresh opportunities.
                            </div>
                        <?php endif; ?>

                        <?php include __DIR__ . '/job-form.php'; ?>
                    </div>
                </div><!--end col-->
            </div><!--grid-->
        </div><!--end container-->

        <div class="container relative mt-8" id="job-results">
            <?php if ($error): ?>
                <div class="mb-6 rounded-md bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm dark:bg-red-900/10 dark:border-red-900/40 dark:text-red-200">
                    We’re having trouble loading job results right now. Please try again in a few minutes
                    or adjust your search.
                </div>
            <?php endif; ?>

            <?php if (!$error && !empty($jobs)): ?>
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2 mb-4">
                    <p class="text-sm text-slate-500">
                        Showing <strong><?php echo (int) $shownCount; ?></strong> job leads
                        <?php if ($keyword !== '' && $location !== ''): ?>
                            for "<strong><?php echo htmlspecialchars($keyword, ENT_QUOTES); ?></strong>"
                            in "<strong><?php echo htmlspecialchars($location, ENT_QUOTES); ?></strong>"
                        <?php else: ?>
                            across the U.S.
                        <?php endif; ?>
                        – Page <?php echo (int) $page; ?>.
                    </p>
                    <p class="text-xs text-slate-400">
                        Powered by
                        <strong><?php echo htmlspecialchars($jobPartnerName, ENT_QUOTES); ?></strong>.
                        Clicking a job opens the application page in a new tab.
                    </p>
                </div>
            <?php endif; ?>

            <div class="grid lg:grid-cols-3 md:grid-cols-2 grid-cols-1 gap-7.5">
                <?php if ($hasValidSearch && !$error && empty($jobs)): ?>
                    <div class="md:col-span-3 text-center">
                        <p class="text-slate-400">
                            No jobs found
                            <?php if ($keyword !== '' && $location !== ''): ?>
                                for "<strong><?php echo htmlspecialchars($keyword, ENT_QUOTES); ?></strong>"
                                in "<strong><?php echo htmlspecialchars($location, ENT_QUOTES); ?></strong>"
                            <?php else: ?>
                                for your current search.
                            <?php endif; ?>
                        </p>
                        <p class="text-slate-400 mt-2 text-sm">
                            Try adjusting the city, using a broader keyword (for example “customer service” instead of a very specific title),
                            or searching for “remote” roles across the U.S.
                        </p>
                    </div>
                <?php elseif (!$error && !empty($jobs)): ?>
                    <?php foreach ($jobsToShow as $job): ?>
                        <?php
                        $title      = $job['title']          ?? 'Job Opportunity';
                        $company    = $job['company']        ?? 'Company';
                        $locLabel   = $job['location_label'] ?? '';
                        $snippetRaw = $job['snippet']        ?? '';
                        $postedRaw  = $job['posted_at']      ?? null;
                        $logoUrl    = $job['logo_url']       ?? null;

                        // Se tiver URL da vaga, monta link passando pelo jobs-out.php com tracking
                        if (!empty($job['url'])) {
                            $link = 'jobs-out.php' . $linkCompleteHref . '&' . http_build_query([
                                'click_source' => 'job_list',
                                'job_url'      => $job['url'],
                                'job_id'       => $job['external_id'] ?? '',
                                'job_price'    => $job['score'] ?? '',
                            ]);
                        } else {
                            $link = '#';
                        }

                        $snippet = cleanSnippet($snippetRaw, 160);
                        $updated = formatJobDate($postedRaw);
                        ?>

                        <a href="<?php echo htmlspecialchars($link, ENT_QUOTES); ?>"
                            target="_blank"
                            rel="nofollow noopener"
                            class="block rounded-md shadow-sm dark:shadow-gray-800 hover:shadow-md dark:hover:shadow-gray-700 transition duration-200 group bg-white dark:bg-slate-900">

                            <div class="p-6">
                                <h5 class="title text-lg font-extrabold group-hover:text-primary">
                                    <?php echo htmlspecialchars($title, ENT_QUOTES); ?>
                                </h5>

                                <?php if ($updated): ?>
                                    <p class="text-slate-400 mt-2 text-sm flex items-center gap-1">
                                        <i class="ri-time-line text-primary"></i>&nbsp;
                                        Posted / updated on <?php echo htmlspecialchars($updated, ENT_QUOTES); ?>
                                    </p>
                                <?php endif; ?>

                                <p class="text-slate-400 mt-3 text-sm">
                                    <?php echo htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                            </div>

                            <div class="flex items-center p-6 border-t border-gray-100 dark:border-gray-800">
                                <div class="size-12 shadow-md dark:shadow-gray-800 rounded-md p-2 bg-white dark:bg-slate-900 flex items-center justify-center">
                                    <?php if (!empty($logoUrl)): ?>
                                        <img
                                            src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>"
                                            alt="<?= htmlspecialchars($company ?: 'Company logo', ENT_QUOTES, 'UTF-8') ?>"
                                            class="max-h-full max-w-full object-contain">
                                    <?php else: ?>
                                        <span class="text-primary font-bold text-sm">
                                            <?= htmlspecialchars(strtoupper(mb_substr($company ?: 'J', 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="ms-3">
                                    <h6 class="mb-0 font-semibold text-base">
                                        <?php echo htmlspecialchars($company, ENT_QUOTES); ?>
                                    </h6>
                                    <?php if ($locLabel): ?>
                                        <span class="text-slate-400 text-sm">
                                            <?php echo htmlspecialchars($locLabel, ENT_QUOTES); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div><!--end grid-->

            <!-- Pagination -->
            <?php if ((!empty($jobs) || $page > 1) && !$error): ?>
                <div class="mt-10 flex items-center justify-center gap-3">
                    <?php if ($prevPageUrl): ?>
                        <a href="<?php echo htmlspecialchars($prevPageUrl, ENT_QUOTES); ?>"
                            class="inline-flex items-center px-4 py-2 border border-gray-200 dark:border-gray-700 rounded-md text-sm font-medium text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-900 hover:bg-gray-50 dark:hover:bg-slate-800 transition">
                            <i class="ri-arrow-left-s-line me-1"></i>
                            Previous
                        </a>
                    <?php else: ?>
                        <span class="inline-flex items-center px-4 py-2 border border-transparent rounded-md text-sm font-medium text-slate-300 dark:text-slate-600 bg-gray-50 dark:bg-slate-900/40 cursor-not-allowed">
                            <i class="ri-arrow-left-s-line me-1"></i>
                            Previous
                        </span>
                    <?php endif; ?>

                    <span class="inline-flex items-center px-3 py-2 text-sm font-medium text-slate-600 dark:text-slate-300">
                        Page <?php echo (int) $page; ?>
                    </span>

                    <?php if ($nextPageUrl): ?>
                        <a href="<?php echo htmlspecialchars($nextPageUrl, ENT_QUOTES); ?>"
                            class="inline-flex items-center px-4 py-2 border border-gray-200 dark:border-gray-700 rounded-md text-sm font-medium text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-900 hover:bg-gray-50 dark:hover:bg-slate-800 transition">
                            Next
                            <i class="ri-arrow-right-s-line ms-1"></i>
                        </a>
                    <?php else: ?>
                        <span class="inline-flex items-center px-4 py-2 border border-transparent rounded-md text-sm font-medium text-slate-300 dark:text-slate-600 bg-gray-50 dark:bg-slate-900/40 cursor-not-allowed">
                            Next
                            <i class="ri-arrow-right-s-line ms-1"></i>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <!-- End Pagination -->

            <!-- CTA para assinatura -->
            <div class="mt-10 md:mt-12 max-w-3xl mx-auto text-center">
                <div class="inline-flex items-center justify-center px-3 py-1 rounded-full text-[11px] font-semibold bg-primary/10 text-primary mb-3 uppercase tracking-wide">
                    Stay ahead of new opportunities
                </div>
                <h2 class="text-xl md:text-2xl font-semibold text-slate-900 dark:text-white mb-2">
                    Want curated job leads like these delivered to your inbox every morning?
                </h2>
                <p class="text-slate-500 dark:text-slate-300 text-sm md:text-base mb-4">
                    <?= $siteTitle ?> scans multiple sources and sends you a short email with 5–10 relevant job leads,
                    so you spend less time searching and more time applying.
                </p>
                <a href="subscribe.php"
                    class="inline-flex items-center justify-center px-6 py-2.5 rounded-md text-sm font-semibold text-white bg-primary hover:bg-primary/90 transition">
                    Get Daily Job Leads by Email
                    <i class="ri-arrow-right-line ms-1 text-[16px]"></i>
                </a>
            </div>

        </div><!--end container-->
    </section><!--end section-->
    <!-- End Section-->


    <?php if (!empty($modalJob)): ?>
        <div id="first-job-modal" style="z-index: 2147483647 !important;"
            class="fixed inset-0 z-[9999] hidden items-center justify-center px-4 py-6 bg-slate-900/75 backdrop-blur-sm">

            <div class="relative w-full max-w-xl rounded-2xl bg-white dark:bg-slate-900 shadow-2xl overflow-hidden">

                <button type="button"
                    id="first-job-modal-close"
                    class="absolute top-4 right-4 z-10 size-8 rounded-full flex items-center justify-center bg-slate-100 dark:bg-slate-800 text-slate-500 hover:text-slate-900 dark:hover:text-white hover:bg-slate-200 dark:hover:bg-slate-700 transition"
                    aria-label="Close modal" style="right: 1rem !important; left: auto !important;">
                    <i class="ri-close-line text-lg"></i>
                </button>

                <div class="p-6 md:p-7">
                    <div class="mb-4">
                        <span class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-3 py-1 text-[11px] font-bold uppercase tracking-wide text-primary">
                            Featured job lead
                        </span>
                    </div>

                    <div class="flex items-start gap-4">
                        <div class="size-12 shrink-0 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-100 dark:border-slate-700 flex items-center justify-center overflow-hidden">
                            <?php if (!empty($modalJob['logo_url'])): ?>
                                <img
                                    src="<?= htmlspecialchars($modalJob['logo_url'], ENT_QUOTES, 'UTF-8') ?>"
                                    alt="<?= htmlspecialchars($modalJob['company'] . ' logo', ENT_QUOTES, 'UTF-8') ?>"
                                    class="max-h-8 max-w-8 object-contain"
                                    loading="lazy"
                                    onerror="this.style.display='none'; this.parentElement.innerHTML='<span class=&quot;text-primary font-bold text-lg&quot;><?= htmlspecialchars(strtoupper(mb_substr($modalJob['company'] ?: 'J', 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>';">
                            <?php else: ?>
                                <span class="text-primary font-bold text-lg">
                                    <?= htmlspecialchars(strtoupper(mb_substr($modalJob['company'] ?: 'J', 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="min-w-0 pr-8">
                            <h3 class="text-lg md:text-xl font-bold text-slate-900 dark:text-white leading-snug">
                                <?= htmlspecialchars($modalJob['title'], ENT_QUOTES, 'UTF-8') ?>
                            </h3>

                            <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                                <?= htmlspecialchars($modalJob['company'], ENT_QUOTES, 'UTF-8') ?>

                                <?php if (!empty($modalJob['location'])): ?>
                                    <span class="mx-1 text-slate-400">•</span>
                                    <?= htmlspecialchars($modalJob['location'], ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </p>

                            <?php if (!empty($modalJob['posted'])): ?>
                                <p class="mt-2 text-xs text-slate-400 flex items-center gap-1">
                                    <i class="ri-time-line text-primary"></i>
                                    Posted / updated on <?= htmlspecialchars($modalJob['posted'], ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($modalJob['snippet'])): ?>
                        <div class="mt-5 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-100 dark:border-slate-700 p-4">
                            <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed line-clamp-4">
                                <?= htmlspecialchars($modalJob['snippet'], ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <button type="button"
                            id="first-job-modal-later"
                            class="inline-flex items-center justify-center rounded-lg px-5 py-3 text-sm font-semibold border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                            View more jobs
                        </button>

                        <button type="button"
                            id="first-job-modal-apply"
                            data-modal-step="<?= (int) $modalJob['step'] ?>"
                            data-apply-url="<?= htmlspecialchars($modalJob['link'], ENT_QUOTES, 'UTF-8') ?>"
                            class="inline-flex items-center justify-center rounded-lg px-5 py-3 text-sm font-semibold text-white bg-primary hover:bg-primary/90 transition">
                            Apply / View job
                            <i class="ri-arrow-right-line ms-1 text-[16px]"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php include __DIR__ . '/partials/footer.php'; ?>

    <!-- Scroll automático + modal pós-click -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var hasJobs = <?php echo !empty($jobs) && !$error ? 'true' : 'false'; ?>;

            if (hasJobs) {
                var el = document.getElementById('job-results');

                if (el) {
                    var offset = el.getBoundingClientRect().top + window.pageYOffset - 120;
                    if (offset < 0) offset = 0;

                    window.scrollTo({
                        top: offset,
                        behavior: 'smooth'
                    });
                }
            }

            var modal = document.getElementById('first-job-modal');
            if (!modal) return;

            var closeBtn = document.getElementById('first-job-modal-close');
            var laterBtn = document.getElementById('first-job-modal-later');
            var applyBtn = document.getElementById('first-job-modal-apply');

            function openFirstJobModal() {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                document.body.classList.add('overflow-hidden');
            }

            function closeFirstJobModal() {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                document.body.classList.remove('overflow-hidden');
            }

            closeBtn?.addEventListener('click', closeFirstJobModal);
            laterBtn?.addEventListener('click', closeFirstJobModal);

            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeFirstJobModal();
                }
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeFirstJobModal();
                }
            });

            applyBtn?.addEventListener('click', function() {
                var currentStep = parseInt(this.getAttribute('data-modal-step') || '2', 10);
                var applyUrl = this.getAttribute('data-apply-url') || '#';

                if (applyUrl && applyUrl !== '#') {
                    window.open(applyUrl, '_blank', 'noopener,noreferrer');
                }

                if (currentStep === 2) {
                    var url = new URL(window.location.href);
                    url.searchParams.set('job_modal_step', '3');
                    url.hash = 'job-results';
                    window.location.href = url.toString();
                    return;
                }

                closeFirstJobModal();
            });

            setTimeout(openFirstJobModal, 800);
        });
    </script>

</body>

</html>