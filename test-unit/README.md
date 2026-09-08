# Unit Tests — Jobs Pages

Testes de integração para `jobs.php` e `jobs-out.php`, usando um banco de dados MySQL isolado criado e destruído a cada execução.

---

## Como rodar

```bash
cd public_html

# Rodar todos os testes
vendor/phpunit/phpunit/phpunit -c test-unit/phpunit.xml

# Com nome de cada teste listado
vendor/phpunit/phpunit/phpunit -c test-unit/phpunit.xml --testdox

# Só uma suite específica
vendor/phpunit/phpunit/phpunit -c test-unit/phpunit.xml --filter JobsPageTest
vendor/phpunit/phpunit/phpunit -c test-unit/phpunit.xml --filter JobsOutPageTest
vendor/phpunit/phpunit/phpunit -c test-unit/phpunit.xml --filter ProviderDbTest
vendor/phpunit/phpunit/phpunit -c test-unit/phpunit.xml --filter HelperFunctionsTest
vendor/phpunit/phpunit/phpunit -c test-unit/phpunit.xml --filter ProviderAdapterTest
```

---

## Banco de dados de teste

- **Nunca usa o banco real.** O bootstrap cria um banco temporário (`bugai_test_<hash>`) usando as credenciais de root (`127.0.0.1:3306`, sem senha).
- O banco é **criado automaticamente** no início da execução e **destruído automaticamente** no final (via `register_shutdown_function`).
- As credenciais do banco real (`.env`) são sobrescritas logo após o carregamento do `config.php`.

---

## Estrutura dos arquivos

```
test-unit/
├── phpunit.xml               # Configuração do PHPUnit
├── bootstrap.php             # Setup global: cria DB, define mocks, carrega autoloader
├── TestCase.php              # Classe base com helpers: db(), runPage(), insertJobClick()
├── README.md                 # Este arquivo
│
├── HelperFunctionsTest.php   # Funções puras (sem DB, sem HTTP)
├── ProviderAdapterTest.php   # Adaptadores de provider (sem DB, sem HTTP)
├── ProviderDbTest.php        # Funções que lêem/escrevem no banco
├── JobsPageTest.php          # Simulação de visita ao jobs.php
├── JobsOutPageTest.php       # Simulação de clique no jobs-out.php
│
├── helpers/
│   └── page_runner.php       # Subprocesso que executa a página isolada
│
└── cache/                    # Cache de provider usado nos testes (nunca o de produção)
```

---

## O que cada suite testa

### `HelperFunctionsTest` — funções puras
- `parseLocationString` — ZIP, cidade/estado, formatos variados
- `appendProviderSubId` — parâmetro `t1` para Talroo, `source_id` para Jooble
- `updateQueryStringParam` — adiciona/atualiza/remove parâmetros de URL
- `getUsStateName` — abreviação para nome completo do estado
- `formatJoobleSalary` — formatação de faixa salarial
- `buildTalrooLocationAttempts` — lista de tentativas de localização
- `cleanSnippet`, `formatJobDate`

### `ProviderAdapterTest` — adaptadores de provider
- `joobleFallBackAdaptJob` — normaliza job da API fallback do Jooble
- `joobleAdaptJob` — normaliza job da API principal do Jooble
- `talrooAdaptJob` — normaliza job da API do Talroo

### `ProviderDbTest` — funções com banco
- `getActiveJobProviderSlugs` — retorna slugs ativos, provider preferido primeiro, sem duplicatas
- `getJobProviderConfig` — lê config do provider via cache
- `getRecordLeadIdByEmail` — busca lead por email (case-insensitive)
- `hasRecentClickForProvider` — detecta clique recente para evitar duplo clique
- `insertJobClickSuspicious` — insere clique suspeito
- `getRecentClickCountForProviderRotation` — contagem para rotação
- `resolveProviderClickRotationTarget` — lógica de rotação de provider

### `JobsPageTest` — simulação de visita ao `jobs.php`
- Clique suspeito quando falta keyword e location
- Inserção de `job_clicks` em requisição válida
- Geração de headline (keyword + cidade + estado, keyword apenas, genérica)
- HTML contém nome do partner e título do job
- Fallback de grid quando não há jobs
- Link do job aponta para `jobs-out.php`
- Campos UTM armazenados corretamente

### `JobsOutPageTest` — simulação de clique no `jobs-out.php`
- Inserção de `job_clicks_out` com campos corretos
- Campos UTM e `provider_job_id` armazenados
- Fallback de UTM para acesso direto (`utm_source=direct`)
- URL de redirect aponta para o job original no primeiro clique
- Parâmetro `t1` adicionado para Talroo
- Parâmetro `source_id` adicionado para Jooble
- Fallback para `job-grid.php` quando sem `job_url`
- Rotação de provider quando há cliques recentes

---

## Como funciona o teste de página (subprocesso)

As páginas `jobs.php` e `jobs-out.php` chamam `exit()` ao final — o que quebraria o processo do PHPUnit se incluídas diretamente. A solução usa um subprocesso:

```
PHPUnit test
  └─ runPage('jobs.php', $getParams, $mockJobs)
       └─ exec("php page_runner.php config_temp.json")
            ├─ Define funções mock (fetchUnifiedJobs, geoLookup, pageRedirect...)
            ├─ Carrega config.php + sobrescreve DB vars para banco de teste
            ├─ Simula $_GET, $_SERVER com User-Agent de browser real
            ├─ register_shutdown_function → captura output + lê DB + grava resultado.json
            ├─ ob_start()
            └─ include jobs.php  (pode chamar exit() à vontade)
       └─ Lê resultado.json → retorna ['output', 'location', 'job_clicks', ...]
```

### Mocks ativos nos testes
| Função | Comportamento no teste |
|---|---|
| `fetchUnifiedJobs()` | Retorna `$mockJobs` passados pelo teste |
| `joobleFetchRawJobs()` | Retorna lista vazia |
| `talrooFetchRawJobs()` | Retorna lista vazia |
| `geoLookupCityStateZipByIp()` | Retorna `['', '', '', '']` (sem chamada HTTP) |
| `geoEnrichLeadLocation()` | No-op (sem atualização de DB de produção) |
| `getJobProvidersCacheDir()` | Aponta para `test-unit/cache/` |
| `pageRedirect()` | Captura a URL em vez de enviar header HTTP |

---

## Arquivos de produção

**Nenhum arquivo de produção é modificado.**

O `bootstrap.php` cria uma cópia paralela ("shadow") dos arquivos em `/tmp/bugai_shadow_<hash>/` antes de cada execução. Os patches são aplicados apenas nas cópias:

| Arquivo copiado | Patch aplicado |
|---|---|
| `includes/functions.php` | Guards `if (!function_exists())` em 6 funções + `pageRedirect()` adicionada |
| `includes/provider_jobs.php` | Guards `if (!function_exists())` em 4 funções de fetch |
| `jobs-out.php` | `header() + exit` substituído por `pageRedirect()` |
| demais `includes/*.php` | Cópia literal, sem alteração |

O diretório shadow é removido automaticamente ao final da execução.
