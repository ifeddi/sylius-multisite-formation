<?php

declare(strict_types=1);

namespace App\Tests\Formation\Agents;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Sécurité de la configuration partagée des agents IA (.claude/).
 *
 * Ce dossier s'exécute avec les droits de quiconque clone le dépôt : ce test signale un affaiblissement
 * (agent de lecture seule qui gagne un outil, hook, mode sans permissions, autorisation générale, fichier inattendu).
 * Il protège contre un oubli ou une erreur, pas contre une pull request malveillante qui modifierait aussi ce test :
 * c'est le rôle de la revue du propriétaire (CODEOWNERS).
 */
final class AgentsConfigurationTest extends TestCase
{
    /** Ensemble exact d'outils par agent : toute évolution est un choix assumé, revu par le propriétaire. */
    private const TOOLS = [
        'explorer' => ['Glob', 'Grep', 'Read'],
        'reviewer' => ['Glob', 'Grep', 'Read'],
        'qa' => ['Bash', 'Glob', 'Grep', 'Read'],
        'backend-dev' => ['Bash', 'Edit', 'Glob', 'Grep', 'Read', 'Write'],
        'test-writer' => ['Bash', 'Edit', 'Glob', 'Grep', 'Read', 'Write'],
        'docs-writer' => ['Edit', 'Glob', 'Grep', 'Read', 'Write'],
    ];

    private const READ_ONLY_AGENTS = ['explorer', 'reviewer'];

    private const ALLOWED_AGENT_KEYS = ['name', 'description', 'tools', 'disallowedTools', 'model', 'permissionMode', 'maxTurns'];

    private const ALLOWED_PERMISSION_MODES = ['default', 'plan'];

    private const REQUIRED_DENY = [
        'Read(./.env.local)',
        'Read(./.env.local.php)',
        'Read(./config/jwt/*.pem)',
        'Read(./config/encryption/*.key)',
        'Read(./dumps/**)',
        'Read(~/.ssh/**)',
        'Edit(./vendor/**)',
        'Bash(git push --force*)',
        'Bash(git reset --hard*)',
        'Bash(docker compose down -v*)',
        'Bash(rm -rf *)',
        'Bash(curl *)',
        'Bash(wget *)',
    ];

    private const REQUIRED_ASK = [
        'Bash(git push *)',
        'Bash(composer update *)',
        'Bash(*sylius:fixtures:load*)',
        'Edit(./.claude/**)',
        'Edit(./.github/**)',
        'Edit(./CLAUDE.md)',
        'Edit(./scripts/**)',
        'Edit(./tests/Formation/Agents/**)',
    ];

    /** Une règle allow ne peut jamais contenir ces motifs (accès réseau, interpréteurs, commandes Docker ou make génériques). */
    private const FORBIDDEN_ALLOW_FRAGMENTS = ['curl', 'wget', 'Bash(sh ', 'Bash(bash *', 'Bash(*)', 'Bash(make ', 'Bash(docker compose exec *', 'Bash(python', 'Bash(php '];

    /** @return iterable<string, array{string}> */
    public static function agentFiles(): iterable
    {
        $files = glob(self::root() . '/.claude/agents/*.md');
        foreach (false === $files ? [] : $files as $file) {
            yield basename($file, '.md') => [$file];
        }
    }

    public function testExactlyTheExpectedAgentsExist(): void
    {
        $names = array_map(static fn (array $row): string => basename($row[0], '.md'), iterator_to_array(self::agentFiles(), false));
        sort($names);
        $expected = array_keys(self::TOOLS);
        sort($expected);

        self::assertSame($expected, $names, 'Les agents de .claude/agents/ ne correspondent pas à la liste attendue.');
    }

    #[DataProvider('agentFiles')]
    public function testAgentIsWellFormed(string $file): void
    {
        $meta = self::frontmatter($file);
        $name = basename($file, '.md');

        self::assertSame($name, $meta['name'] ?? null, 'Le champ name doit être le nom du fichier.');
        self::assertIsString($meta['description'] ?? null);
        self::assertNotSame('', trim($meta['description']), 'Le champ description est obligatoire (il pilote la délégation).');
        self::assertIsInt($meta['maxTurns'] ?? null, 'maxTurns doit borner la durée de l\'agent.');
        self::assertGreaterThan(0, $meta['maxTurns']);
        self::assertSame([], array_values(array_diff(array_keys($meta), self::ALLOWED_AGENT_KEYS)), 'Clé de frontmatter non autorisée (hooks, mcpServers, skills, memory, isolation, etc.).');
    }

    #[DataProvider('agentFiles')]
    public function testAgentHasExactlyItsExpectedTools(string $file): void
    {
        $name = basename($file, '.md');

        self::assertArrayHasKey($name, self::TOOLS, sprintf('Agent « %s » inattendu : ajoutez-le explicitement à ce test.', $name));
        self::assertSame(self::TOOLS[$name], self::toolList(self::frontmatter($file), 'tools'), sprintf('Les outils de « %s » ont changé.', $name));
    }

    #[DataProvider('agentFiles')]
    public function testAgentDoesNotWeakenPermissions(string $file): void
    {
        $mode = self::frontmatter($file)['permissionMode'] ?? 'default';

        self::assertContains($mode, self::ALLOWED_PERMISSION_MODES, 'Mode de permissions interdit dans un agent partagé.');
    }

    public function testReadOnlyAgentsExplicitlyRefuseWriteAndShellTools(): void
    {
        foreach (self::READ_ONLY_AGENTS as $agent) {
            $meta = self::frontmatter(self::root() . '/.claude/agents/' . $agent . '.md');

            self::assertSame(['Bash', 'Edit', 'Write'], self::toolList($meta, 'disallowedTools'), sprintf('« %s » doit refuser Write, Edit et Bash en plus de sa liste d\'outils.', $agent));
        }
    }

    public function testClaudeDirectoryContainsOnlyTheExpectedFiles(): void
    {
        $root = self::root() . '/.claude';
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }

        foreach ($found as $path) {
            if ('settings.json' === $path) {
                continue;
            }
            self::assertSame(1, preg_match('#^agents/[a-z-]+\.md$#', $path), sprintf('Fichier inattendu dans .claude/ : %s (commandes, skills, hooks et réglages locaux sont interdits).', $path));
        }
        self::assertFileDoesNotExist(self::root() . '/.mcp.json', 'Un .mcp.json partagé démarrerait des serveurs externes.');
    }

    public function testSharedSettingsAreMinimalAndKeepTheSecretsProtected(): void
    {
        $settings = self::settings();

        self::assertSame(['permissions'], array_keys($settings), 'Seule la clé permissions est autorisée (pas de hooks, env, MCP, defaultMode ni additionalDirectories).');
        $permissions = $settings['permissions'];
        self::assertIsArray($permissions);
        self::assertSame([], array_values(array_diff(array_keys($permissions), ['deny', 'ask', 'allow'])), 'Clés de permissions autorisées : deny, ask, allow.');

        $deny = self::rules($permissions, 'deny');
        $ask = self::rules($permissions, 'ask');
        $allow = self::rules($permissions, 'allow');

        foreach (self::REQUIRED_DENY as $rule) {
            self::assertContains($rule, $deny, sprintf('La règle deny « %s » est obligatoire.', $rule));
        }
        foreach (self::REQUIRED_ASK as $rule) {
            self::assertContains($rule, $ask, sprintf('La règle ask « %s » est obligatoire.', $rule));
        }
        foreach ($allow as $rule) {
            self::assertStringStartsWith('Bash(', $rule, 'Seules des commandes précises peuvent être autorisées (jamais Read, Edit ou Write).');
            foreach (self::FORBIDDEN_ALLOW_FRAGMENTS as $fragment) {
                self::assertStringNotContainsString($fragment, $rule, sprintf('Autorisation trop large : %s', $rule));
            }
        }
    }

    public function testPersonalSettingsAreNeverVersioned(): void
    {
        self::assertStringContainsString('/.claude/settings.local.json', self::read('/.gitignore'));
    }

    public function testCodeownersProtectTheAgentConfiguration(): void
    {
        $codeowners = self::read('/.github/CODEOWNERS');

        foreach (['/.claude/', '/CLAUDE.md', '/.github/', '/.mcp.json', '/scripts/', '/tests/Formation/Agents/'] as $path) {
            self::assertSame(1, preg_match('#^' . preg_quote($path, '#') . '\s+@\S+#m', $codeowners), sprintf('CODEOWNERS doit protéger %s.', $path));
        }
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $relativePath): string
    {
        $content = file_get_contents(self::root() . $relativePath);
        self::assertIsString($content, $relativePath . ' est illisible.');

        return $content;
    }

    /** @return array<string, mixed> */
    private static function settings(): array
    {
        $settings = json_decode(self::read('/.claude/settings.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($settings);

        /** @var array<string, mixed> $typedSettings */
        $typedSettings = $settings;

        return $typedSettings;
    }

    /**
     * @param array<array-key, mixed> $permissions
     *
     * @return list<string>
     */
    private static function rules(array $permissions, string $kind): array
    {
        $rules = $permissions[$kind] ?? [];
        self::assertIsArray($rules);
        foreach ($rules as $rule) {
            self::assertIsString($rule);
        }

        /** @var list<string> $typedRules */
        $typedRules = array_values($rules);

        return $typedRules;
    }

    /** @return array<string, mixed> */
    private static function frontmatter(string $file): array
    {
        $content = file_get_contents($file);
        self::assertIsString($content);
        if (1 !== preg_match('/\A---\R(.*?)\R---\R/s', $content, $matches)) {
            self::fail(basename($file) . ' : frontmatter absent.');
        }

        $meta = Yaml::parse($matches[1]);
        self::assertIsArray($meta, basename($file) . ' : frontmatter invalide.');

        /** @var array<string, mixed> $typedMeta */
        $typedMeta = $meta;

        return $typedMeta;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return list<string>
     */
    private static function toolList(array $meta, string $key): array
    {
        $value = $meta[$key] ?? '';
        self::assertIsString($value);
        $tools = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $tool): bool => '' !== $tool));
        sort($tools);

        return $tools;
    }
}
