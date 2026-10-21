<?php
declare(strict_types=1);

namespace Wiki\Application;

use Wiki\Config\Installation;
use Wiki\Config\Settings;
use Wiki\Git\GitClient;
use Wiki\Markdown\HtmlSanitizer;
use Wiki\Markdown\MarkdownRenderer;
use Wiki\Navigation\NavBuilder;
use Wiki\Process\ProcessRunner;
use Wiki\Search\SearchIndex;
use Wiki\Security\RepoUrlValidator;
use Wiki\Support\Clock;
use Wiki\Support\Logger;
use Wiki\Support\Paths;
use Wiki\Support\SystemClock;
use Wiki\Sync\CandidateBuilder;
use Wiki\Sync\CandidateValidator;
use Wiki\Sync\Launcher;
use Wiki\Sync\SetupService;
use Wiki\Sync\StateStore;
use Wiki\Sync\Synchronizer;
use Wiki\Sync\VersionStore;

/** Tiny hand-wired service container (no framework, no reflection magic). */
final class Services
{
    public readonly Paths $paths;
    public readonly Settings $settings;
    public readonly Clock $clock;
    public readonly Logger $logger;
    public readonly Installation $installation;
    public readonly StateStore $state;
    public readonly VersionStore $versions;
    public readonly GitClient $git;
    public readonly NavBuilder $nav;
    public readonly SearchIndex $search;
    public readonly HtmlSanitizer $sanitizer;
    public readonly MarkdownRenderer $renderer;
    public readonly RepoUrlValidator $urlValidator;
    public readonly CandidateBuilder $candidates;

    /** @param array<string,mixed> $settingOverrides */
    public function __construct(?Paths $paths = null, ?Clock $clock = null, array $settingOverrides = [])
    {
        $this->paths = $paths ?? new Paths();
        $this->settings = new Settings($this->paths, $settingOverrides);
        $this->clock = $clock ?? new SystemClock();
        $this->logger = new Logger($this->paths, $this->settings, $this->clock);
        $this->installation = new Installation($this->paths);
        $this->state = new StateStore($this->paths);
        $this->versions = new VersionStore($this->paths, $this->clock);
        $this->git = new GitClient($this->paths, $this->settings, new ProcessRunner());
        $this->nav = new NavBuilder($this->paths, $this->settings);
        $this->search = new SearchIndex($this->paths, $this->settings);
        $this->sanitizer = new HtmlSanitizer($this->paths);
        $this->renderer = new MarkdownRenderer($this->paths, $this->settings, $this->sanitizer);
        $this->urlValidator = new RepoUrlValidator($this->settings);
        $this->candidates = new CandidateBuilder(
            $this->paths, $this->settings, $this->logger, $this->git, $this->versions,
            new CandidateValidator($this->settings), $this->nav, $this->search
        );
    }

    public function synchronizer(): Synchronizer
    {
        return new Synchronizer(
            $this->settings, $this->clock, $this->logger, $this->installation, $this->git,
            $this->state, $this->versions, $this->candidates
        );
    }

    public function launcher(): Launcher
    {
        return new Launcher($this->paths, $this->settings, $this->clock, $this->logger, $this->state, $this->versions);
    }

    public function setup(): SetupService
    {
        return new SetupService(
            $this->paths, $this->settings, $this->clock, $this->logger, $this->installation, $this->state,
            $this->versions, $this->git, $this->candidates, $this->urlValidator
        );
    }
}
