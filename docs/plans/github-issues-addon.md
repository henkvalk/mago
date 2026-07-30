# Plan: `github_issues` Skill (Add-on Module)

**Module:** `MaggyAssistant_GitHub` (apart van Base)

Shopeigenaren kunnen direct vanuit de chat bugs/issues melden die als GitHub issue bij hun developer terechtkomen. De AI helpt met structureren en voegt automatisch store context toe.

## Use Cases

- "De checkout pagina geeft een fout bij iDEAL betaling" → AI vraagt door, maakt gestructureerd issue
- "Er is een bug op de productpagina van SKU-123" → issue met link naar product
- "Toon openstaande issues" → overzicht wat er al gemeld is
- "Voeg toe aan issue #12 dat het ook op mobile gebeurt" → comment op bestaand issue
- "Issue #8 is opgelost" → sluit issue

## Actions

| Action | Type | Beschrijving |
|--------|------|-------------|
| `create_issue` | write | Maak GitHub issue aan met auto-context |
| `list_issues` | read | Lijst openstaande issues |
| `get_issue` | read | Issue details + comments |
| `add_comment` | write | Comment toevoegen aan issue |
| `close_issue` | write | Issue sluiten |

## Automatische Store Context

Bij `create_issue` voegt de module automatisch een context block toe aan de issue body:

```markdown
---
**Store context (auto-generated)**
- Store URL: https://www.example.com
- Magento version: 2.4.8
- PHP version: 8.3.12
- Reported by: Admin User (admin@example.com)
- Reported at: 2026-07-30 14:22:00 UTC
```

Dit wordt opgebouwd via:
- `Magento\Framework\App\ProductMetadataInterface` → Magento versie
- `phpversion()` → PHP versie
- Store base URL via `StoreManagerInterface`
- Admin user info via de `_admin_user_id` parameter

## GitHub API Endpoints

| Endpoint | Method | Doel |
|----------|--------|------|
| `repos/{owner}/{repo}/issues` | POST | Issue aanmaken |
| `repos/{owner}/{repo}/issues` | GET | Issues listen |
| `repos/{owner}/{repo}/issues/{number}` | GET | Issue details |
| `repos/{owner}/{repo}/issues/{number}/comments` | POST | Comment toevoegen |
| `repos/{owner}/{repo}/issues/{number}` | PATCH | Issue sluiten |

Auth: `Authorization: Bearer {personal_access_token}`

## Module Structuur

```
MaggyAssistant/GitHub/
├── registration.php
├── composer.json
├── etc/
│   ├── module.xml
│   ├── di.xml
│   ├── config.xml
│   └── adminhtml/
│       └── system.xml
├── Api/
│   └── Config/
│       └── RepositoryInterface.php
├── Model/
│   └── Config/
│       └── Repository.php
├── Service/
│   ├── GitHubClient.php
│   ├── StoreContextCollector.php
│   └── Skills/
│       ├── GitHubIssues.php
│       └── GitHubIssues/
│           ├── CreateIssueAction.php
│           ├── ListIssuesAction.php
│           ├── GetIssueAction.php
│           ├── AddCommentAction.php
│           └── CloseIssueAction.php
```

## Config (Stores > Config > Maggy Assistant > GitHub)

| Path | Type | Beschrijving |
|------|------|-------------|
| `maggy_github/general/enabled` | bool | Module aan/uit |
| `maggy_github/general/token` | obscure | GitHub Personal Access Token |
| `maggy_github/general/repository` | text | Default repo (bv `acme/webshop-issues`) |
| `maggy_github/general/default_labels` | text | Komma-separated labels (bv `bug,from-store`) |

## Service Classes

### GitHubClient
```php
class GitHubClient
{
    private const API_BASE = 'https://api.github.com';

    public function __construct(
        private readonly ConfigRepositoryInterface $config,
        private readonly Json $json
    ) {}

    public function get(string $endpoint, array $params = []): array
    public function post(string $endpoint, array $body): array
    public function patch(string $endpoint, array $body): array

    // Alle calls via cURL met:
    // - Authorization: Bearer {token}
    // - Accept: application/vnd.github.v3+json
    // - User-Agent: MaggyAssistant-GitHub/1.0
}
```

### StoreContextCollector
```php
class StoreContextCollector
{
    public function __construct(
        private readonly ProductMetadataInterface $productMetadata,
        private readonly StoreManagerInterface $storeManager,
        private readonly UserFactory $userFactory
    ) {}

    public function collect(int $adminUserId): array
    {
        return [
            'store_url' => $this->storeManager->getStore()->getBaseUrl(),
            'magento_version' => $this->productMetadata->getVersion(),
            'magento_edition' => $this->productMetadata->getEdition(),
            'php_version' => phpversion(),
            'admin_user' => $this->getAdminName($adminUserId),
            'admin_email' => $this->getAdminEmail($adminUserId),
            'reported_at' => date('Y-m-d H:i:s T'),
        ];
    }

    public function formatAsMarkdown(array $context): string
    {
        // Returns markdown block voor in issue body
    }
}
```

## Action Details

### `create_issue`
- **Params:**
  - `title` (string, verplicht)
  - `description` (string, verplicht — door AI gestructureerd)
  - `labels` (string, optional — komma-separated, merged met defaults)
  - `repository` (string, optional — override default repo)
  - `priority` (string, optional: "low"/"medium"/"high"/"critical" — wordt label)
- **Implementatie:**
  - Bouw body: description + store context block (via StoreContextCollector)
  - Labels: merge default_labels + opgegeven labels + priority label
  - `POST /repos/{owner}/{repo}/issues`
- **Return:** `issue_number`, `title`, `url` (GitHub web URL), `labels`

### `list_issues`
- **Params:**
  - `state` (string, default: "open" — "open"/"closed"/"all")
  - `labels` (string, optional — filter op labels)
  - `repository` (string, optional)
  - `limit` (int, default: 10)
- **Implementatie:** `GET /repos/{owner}/{repo}/issues?state=open&per_page=10`
- **Return:** Array met `number`, `title`, `state`, `labels`, `created_at`, `url`
- **Filter:** Exclude pull requests (GitHub API returns PRs in issues endpoint)

### `get_issue`
- **Params:**
  - `issue_number` (int, verplicht)
  - `repository` (string, optional)
- **Implementatie:**
  - `GET /repos/{owner}/{repo}/issues/{number}`
  - `GET /repos/{owner}/{repo}/issues/{number}/comments` (apart, max 10)
- **Return:** `number`, `title`, `body`, `state`, `labels`, `created_at`, `comments` array, `url`

### `add_comment`
- **Params:**
  - `issue_number` (int, verplicht)
  - `comment` (string, verplicht)
  - `repository` (string, optional)
- **Implementatie:** `POST /repos/{owner}/{repo}/issues/{number}/comments`
- **Return:** `comment_id`, `issue_number`, `url`

### `close_issue`
- **Params:**
  - `issue_number` (int, verplicht)
  - `comment` (string, optional — afsluitend comment)
  - `repository` (string, optional)
- **Implementatie:**
  - Als comment: eerst `POST .../comments`
  - Dan `PATCH /repos/{owner}/{repo}/issues/{number}` met `state: "closed"`
- **Return:** Success message met issue nummer

## DI Registratie

```xml
<!-- In MaggyAssistant_GitHub etc/di.xml -->

<!-- Register skill in Base's ToolRegistry -->
<type name="MaggyAssistant\Base\Service\Tool\ToolRegistry">
    <arguments>
        <argument name="tools" xsi:type="array">
            <item name="github_issues" xsi:type="object">
                MaggyAssistant\GitHub\Service\Skills\GitHubIssues
            </item>
        </argument>
    </arguments>
</type>

<!-- Wire actions -->
<type name="MaggyAssistant\GitHub\Service\Skills\GitHubIssues">
    <arguments>
        <argument name="actions" xsi:type="array">
            <item name="create_issue" xsi:type="object">
                MaggyAssistant\GitHub\Service\Skills\GitHubIssues\CreateIssueAction
            </item>
            <item name="list_issues" xsi:type="object">
                MaggyAssistant\GitHub\Service\Skills\GitHubIssues\ListIssuesAction
            </item>
            <item name="get_issue" xsi:type="object">
                MaggyAssistant\GitHub\Service\Skills\GitHubIssues\GetIssueAction
            </item>
            <item name="add_comment" xsi:type="object">
                MaggyAssistant\GitHub\Service\Skills\GitHubIssues\AddCommentAction
            </item>
            <item name="close_issue" xsi:type="object">
                MaggyAssistant\GitHub\Service\Skills\GitHubIssues\CloseIssueAction
            </item>
        </argument>
    </arguments>
</type>
```

## Aandachtspunten

1. **Aparte module** — `MaggyAssistant_GitHub` met dependency op `MaggyAssistant_Base`. Eigen composer package, eigen repo.
2. **Token security** — GitHub PAT opslaan als `obscure` type in system.xml (encrypted in DB). Nooit loggen of teruggeven aan de AI.
3. **Repository formaat** — Altijd `owner/repo` format. Valideer bij opslaan in config.
4. **Rate limiting** — GitHub API heeft rate limits (5000/uur voor authenticated). Niet relevant voor normaal gebruik, maar goed om errors netjes af te vangen.
5. **Pull requests uitsluiten** — GitHub's issues endpoint retourneert ook PRs. Filter op `pull_request` key in response.
6. **Platform extensibility** — GitHubClient is specifiek voor GitHub. Later kunnen Jira, Linear, etc. als aparte modules met eigen clients. De skill pattern blijft hetzelfde.
7. **AI instructions** — De AI moet doorvragen bij vage meldingen: "Kun je beschrijven wat je zag?", "Op welke pagina?", "Welke browser?". Dit wordt via `getBaseInstructions()` gestuurd.
