<?php
// Remove o "?" se tiver
$originalQueryString = ltrim($linkCompleteHref, '?');

// Transforma em array associativo
$allParams = [];
parse_str($originalQueryString, $allParams);

// Campos que NÃO devem virar hidden (porque já existem no form como visíveis)
$excludeParams = ['keyword', 'location', 'search'];
?>
<form action="job-grid.php" method="GET" id="job-search-form">
    <div class="registration-form relative text-slate-900 text-start">
        <div class="grid lg:grid-cols-3 md:grid-cols-2 grid-cols-1 lg:gap-0 gap-6 lg:divide-x lg:divide-gray-200 lg:dark:divide-gray-700">
            <!-- Job keyword -->
            <div class="filter-search-form relative">
                <i class="ri-briefcase-line absolute top-[48%] -translate-y-1/2 start-3 z-1 text-primary text-[20px]"></i>
                <input
                    name="keyword"
                    type="text"
                    id="keyword"
                    class="form-input lg:rounded-t-sm lg:rounded-e-none lg:rounded-b-none lg:rounded-s-sm text-slate-400 lg:outline-0 w-full filter-input-box bg-gray-50 dark:bg-slate-800 border-0 focus:ring-0"
                    placeholder="Search your keywords"
                    value="<?php echo htmlspecialchars($keyword ?? '', ENT_QUOTES); ?>">
            </div>

            <!-- Location -->
            <div class="filter-search-form relative">
                <i class="ri-map-pin-line absolute top-[48%] -translate-y-1/2 start-3 z-1 text-primary text-[20px]"></i>
                <input
                    name="location"
                    type="text"
                    id="location"
                    class="form-input lg:rounded-t-sm lg:rounded-e-none lg:rounded-b-none lg:rounded-s-sm text-slate-400 lg:outline-0 w-full filter-input-box bg-gray-50 dark:bg-slate-800 border-0 focus:ring-0"
                    placeholder="City, State or Country"
                    value="<?php echo htmlspecialchars($location ?? '', ENT_QUOTES); ?>">
            </div>

            <!-- Submit -->
            <input
                type="submit"
                id="search"
                name="search"
                style="height: 60px;"
                class="py-2 px-5 inline-block font-semibold tracking-wide border align-middle duration-500 text-base text-center bg-primary hover:bg-primary-700 border-primary hover:border-primary-700 text-white searchbtn lg:rounded-t-none lg:rounded-e-sm lg:rounded-b-sm lg:rounded-s-none rounded-lg w-full"
                value="Search">
        </div>

        <?php
        // Gera os inputs hidden automaticamente para todos os outros parâmetros
        foreach ($allParams as $key => $value) {
            if (in_array($key, $excludeParams, true)) {
                continue;
            }

            // Se vier array (raro em query string simples), você pode tratar aqui;
            // por enquanto, assumimos scalar.
            echo '<input type="hidden" name="'
                . htmlspecialchars($key, ENT_QUOTES)
                . '" value="'
                . htmlspecialchars($value, ENT_QUOTES)
                . '">' . "\n";
        }
        ?>

        <!-- Error message -->
        <div
            id="job-search-error"
            class="hidden mt-3 text-sm text-red-600 bg-red-50 border border-red-200 rounded-md px-3 py-2"></div>
    </div><!--end container-->
</form>