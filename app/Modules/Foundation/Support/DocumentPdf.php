<?php

namespace App\Modules\Foundation\Support;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Turns a page of this application into a PDF by printing it in a browser.
 *
 * ⭐⭐ THE POINT IS THAT THERE IS NO SECOND TEMPLATE. The browser navigates to
 * the same URL a person opens, so the PDF is the document - not a reconstruction
 * of it that will drift the first time somebody edits one and forgets the other.
 * The A4 page size and 12mm margin come from the application's own `@page` rule,
 * which is why nothing about paper is configured here.
 *
 * ⚠ Bengali is the reason this is a browser at all. See config/pdf.php.
 *
 * ⚠ NOT `final`, deliberately: it is resolved from the container and stood in
 * for by a double in the tests, which is how "the server has no browser" and
 * "the page came back 403" are exercised without installing a browser in CI.
 */
class DocumentPdf
{
    /**
     * Is a browser actually available? Answered by looking, not by trusting a
     * setting: a configured path that does not exist is the same as none.
     *
     * ⚠ Deliberately NOT memoised in a static. It is consulted once per Inertia
     * response and costs a few is_file() calls, which is cheaper than static
     * state that outlives a test and answers the next one wrongly.
     */
    public function isAvailable(): bool
    {
        return $this->binary() !== null;
    }

    /**
     * @return string the PDF bytes
     *
     * @throws RuntimeException when no browser is available or it fails
     */
    public function render(string $url): string
    {
        $binary = $this->binary();

        if ($binary === null) {
            throw new RuntimeException('No browser is installed for PDF export.');
        }

        // ⚠ A FRESH PROFILE PER RENDER, and it is not about tidiness. A shared
        // profile means a shared cookie jar, and this browser is handed a
        // one-shot credential for somebody else's document on the next call.
        $workspace = $this->workspace();
        $file = $workspace.DIRECTORY_SEPARATOR.'document.pdf';

        $process = new Process([
            $binary,
            '--headless=new',
            '--disable-gpu',
            // Running as root in a container, which Chrome refuses to do with a
            // sandbox. Acceptable here and nowhere else: this browser opens one
            // page from this application and is then destroyed.
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--hide-scrollbars',
            '--disable-extensions',
            // ⚠ Chrome's own header and footer are a URL and a page number in
            // the margins. On a document a customer keeps, the URL includes the
            // render token.
            '--no-pdf-header-footer',
            '--print-to-pdf-no-header',
            '--run-all-compositor-stages-before-draw',
            '--virtual-time-budget='.config('pdf.virtual_time_budget'),
            '--user-data-dir='.$workspace,
            '--print-to-pdf='.$file,
            $url,
        ]);

        $process->setTimeout((float) config('pdf.timeout'));

        try {
            $process->run();

            if (! is_file($file) || filesize($file) === 0) {
                throw new RuntimeException(
                    'The browser produced no document. '.trim(substr($process->getErrorOutput(), -400))
                );
            }

            return (string) file_get_contents($file);
        } finally {
            $this->deleteWorkspace($workspace);
        }
    }

    /**
     * ⚠ Tries the configured name first, then the names the same browser ships
     * under on different systems. Alpine installs `chromium-browser`, Debian
     * `chromium`, and a developer machine has Chrome under its own name - and a
     * feature that only works on the deployment machine cannot be tested.
     */
    private function binary(): ?string
    {
        $configured = (string) config('pdf.binary');

        if ($configured !== '' && is_file($configured) && is_executable($configured)) {
            return $configured;
        }

        $finder = new ExecutableFinder;

        foreach (array_filter([$configured, 'chromium-browser', 'chromium', 'google-chrome', 'chrome']) as $name) {
            $found = $finder->find($name);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function workspace(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'erp-pdf-'.bin2hex(random_bytes(8));

        if (! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Could not create a workspace for the PDF render.');
        }

        return $path;
    }

    private function deleteWorkspace(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
