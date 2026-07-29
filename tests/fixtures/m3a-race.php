<?php

declare(strict_types=1);

use App\Actions\Installation\BootstrapInstallation;
use App\Identity\RegistrationData;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1]]);
Illuminate\Support\Facades\DB::purge('sqlite');

try {
    $operation = $argv[2] ?? '';
    if ($operation === 'setup') {
        app(BootstrapInstallation::class)(new RegistrationData($argv[3], $argv[4], $argv[5]), $argv[6]);
    } elseif ($operation === 'accept') {
        $invitation = App\Models\OrganizationInvitation::query()->findOrFail((int) $argv[3]);
        $user = App\Models\User::query()->findOrFail((int) $argv[4]);
        app(App\Organizations\InvitationAcceptanceService::class)->accept($invitation, $user);
    } elseif ($operation === 'revoke') {
        $invitation = App\Models\OrganizationInvitation::query()->findOrFail((int) $argv[3]);
        app(App\Organizations\InvitationService::class)->revoke($invitation);
    } elseif ($operation === 'demote') {
        $actor = App\Models\User::query()->findOrFail((int) $argv[3]);
        $target = App\Models\OrganizationMembership::query()->findOrFail((int) $argv[4]);
        app(App\Organizations\MembershipService::class)->changeRole($actor, $target, App\Enums\OrganizationRole::Member);
    } elseif ($operation === 'remove') {
        $actor = App\Models\User::query()->findOrFail((int) $argv[3]);
        $target = App\Models\OrganizationMembership::query()->findOrFail((int) $argv[4]);
        app(App\Organizations\MembershipService::class)->remove($actor, $target);
    } else {
        exit(64);
    }
    echo "won\n";
    exit(0);
} catch (NotFoundHttpException) {
    echo "already-bootstrapped\n";
    exit(44);
} catch (Illuminate\Validation\ValidationException|Illuminate\Database\Eloquent\ModelNotFoundException|Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $exception) {
    fwrite(STDERR, $exception::class . ": invariant loser\n");
    exit(42);
} catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
    if ($exception->getStatusCode() === 403) {
        fwrite(STDERR, $exception::class . ": invariant loser\n");
        exit(42);
    }
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
    exit(1);
} catch (Illuminate\Database\QueryException $exception) {
    $message = mb_strtolower($exception->getMessage());
    if (str_contains($message, 'locked') || str_contains($message, 'busy')) {
        fwrite(STDERR, $exception::class . ": sqlite lock failure\n");
        exit(70);
    }
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
    exit(1);
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable::class . ': ' . $throwable->getMessage() . "\n");
    exit(1);
}
