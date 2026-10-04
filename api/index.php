<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  JSON API router
 * ---------------------------------------------------------------------------
 * Single entry point:   /api/index.php?action=<group>.<method>
 *
 *   GET  /api/index.php?action=health
 *   GET  /api/index.php?action=chore.board&from=2026-01-01&to=2026-01-07
 *   POST /api/index.php?action=chore.complete      (JSON body, X-CSRF-Token)
 *
 * Conventions
 *   * every response is {ok, data} or {ok:false, error:{code,message,details}}
 *   * auth is a session cookie, OR  Authorization: Bearer <api token>
 *   * mutating verbs require X-CSRF-Token (session auth only)
 *   * ?verify=1 on a read endpoint runs the algorithm self-test first
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Bootstrap.php';

header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

/* -------------------------------------------------------------------------- */
/*  Fatal-error guard                                                         */
/* -------------------------------------------------------------------------- */
/*
 * Parse errors and other E_* fatals are NOT catchable, so without this the
 * endpoint returns an HTML error page. The JS client cannot parse that, so it
 * shows only "Request failed (500)" and the real cause is invisible. This turns
 * any fatal into the same JSON envelope the client already understands, so the
 * message reaches both the UI and the error log.
 */
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null || !in_array(
        $err['type'],
        [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
        true
    )) {
        return;
    }

    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? '?');
    error_log(sprintf(
        '[FlatMate][api][fatal] action=%s %s in %s:%d',
        $action,
        $err['message'],
        $err['file'],
        $err['line']
    ));
    Diag::record('fatal', $err['message'], [
        'type' => 'php:' . $err['type'],
        'file' => $err['file'],
        'line' => $err['line'],
        'action' => $action,
    ]);

    if (headers_sent()) {
        return;                     // too late to replace whatever leaked out
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $dev = config('app.env', 'local') !== 'production';
    echo json_encode([
        'ok'    => false,
        'error' => [
            'code'    => 'fatal_error',
            'message' => $dev ? $err['message'] : 'A fatal server error occurred.',
            'details' => $dev ? ['action' => $action, 'file' => $err['file'], 'line' => $err['line']] : [],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

/* -------------------------------------------------------------------------- */
/*  Route table                                                               */
/* -------------------------------------------------------------------------- */
$ROUTES = [

    // ---- meta ------------------------------------------------------------
    'health'            => ['GET',  'public', fn() => ['db' => Database::health(), 'version' => FLATMATE_VERSION]],
    'meta'              => ['GET',  'auth',   fn() => [
        'days'  => MealService::DAY_NAMES,
        'meals' => MealService::MEAL_TYPES,
        'splits'=> array_map(fn($s) => [
            'value' => $s,
            'label' => match ($s) {
                'equal'      => 'Split equally among everyone',
                'selective'  => 'Split with selected people only',
                'shares'     => 'Split by custom shares',
                'meal_based' => 'Split only among people who ate',
            },
        ], ExpenseService::SPLIT_TYPES),
        'categories' => NoticeBoard::CATEGORIES,
        'currency'   => config('app.currency'),
    ]],

    // ---- auth ------------------------------------------------------------
    'auth.login'        => ['POST', 'public', fn(array $i) => Auth::attempt(
        (string) $i['email'], (string) $i['password'], !empty($i['remember'])),
        ['email', 'password']],
    'auth.magic_request'=> ['POST', 'public', fn(array $i) => Auth::issueMagicLink((string) $i['email'])],
    'auth.magic_redeem' => ['POST', 'public', fn(array $i) => Auth::redeemMagicLink((string) $i['token'])],
    'auth.logout'       => ['POST', 'auth',   function (): array {
        Auth::logout();
        return ['ok' => true];
    }],
    'auth.me'           => ['GET',  'auth',   fn() => Auth::user()],
    'auth.csrf'         => ['GET',  'auth',   fn() => ['token' => csrf_token()]],

    // ---- dashboard -------------------------------------------------------
    'dashboard'         => ['GET',  'auth',   fn() => DashboardService::forUser(
        Auth::apartmentId(), (int) Auth::id())],
    'activity'          => ['GET',  'auth',   fn(array $i) => ActivityLog::feed(
        Auth::apartmentId(), (int) ($i['limit'] ?? 40), (int) ($i['offset'] ?? 0))],

    // ---- chores ----------------------------------------------------------
    'chore.board'       => ['GET',  'auth',   fn(array $i) => DutyScheduler::board(
        Auth::apartmentId(),
        (string) ($i['from'] ?? DutyScheduler::today()),
        (string) ($i['to']   ?? DutyScheduler::today(7)),
        isset($i['user_id']) ? (int) $i['user_id'] : null)],
    'chore.mine'        => ['GET',  'auth',   fn() => DutyScheduler::forUser(
        Auth::apartmentId(), (int) Auth::id(), (int) ($_GET['days'] ?? 14))],
    'chore.areas'       => ['GET',  'auth',   fn() => DashboardService::choreAreas(Auth::apartmentId())],
    'chore.area'        => ['GET',  'auth',   fn(array $i) => DashboardService::choreArea(
        Auth::apartmentId(), (int) $i['id'])],
    'chore.upcoming'    => ['GET',  'auth',   fn(array $i) => DutyScheduler::upcoming((int) $i['area_id'], (int) ($i['count'] ?? 7))],
    'chore.fairness'    => ['GET',  'auth',   fn(array $i) => DutyScheduler::fairness(
        Auth::apartmentId(), isset($i['days']) ? (int) $i['days'] : null,
        isset($i['area_id']) ? (int) $i['area_id'] : null)],
    'chore.complete'    => ['POST', 'auth',   fn(array $i) => DutyScheduler::complete(
        (int) $i['task_id'], (int) Auth::id(), Auth::isAdmin(), $i['note'] ?? null, $i['proof'] ?? null), ['task_id']],
    'chore.verify'      => ['POST', 'admin',  fn(array $i) => DutyScheduler::verify(
        (int) $i['task_id'], (int) Auth::id(), $i['note'] ?? null), ['task_id']],
    'chore.skip'        => ['POST', 'auth',   fn(array $i) => DutyScheduler::skip(
        (int) $i['task_id'], (int) Auth::id(), Auth::isAdmin(), (string) $i['reason']),
        ['task_id', 'reason']],
    'chore.reassign'    => ['POST', 'admin',  fn(array $i) => DutyScheduler::reassign(
        (int) $i['task_id'], (int) $i['user_id']), ['task_id', 'user_id']],
    'chore.generate'    => ['POST', 'admin',  fn(array $i) => DutyScheduler::generate(
        Auth::apartmentId(), $i['from'] ?? null, $i['to'] ?? null)],
    'chore.rotate'      => ['POST', 'admin',  fn(array $i) => DutyScheduler::rotate(
        (int) $i['area_id'], (int) ($i['steps'] ?? 1)), ['area_id']],
    'chore.create_area' => ['POST', 'admin',  fn(array $i) => DashboardService::createChoreArea(
        Auth::apartmentId(), $i), ['name', 'scope']],
    'chore.update_area' => ['POST', 'admin',  fn(array $i) => DashboardService::updateChoreArea(
        Auth::apartmentId(), $i), ['id']],
    'chore.delete_area' => ['POST', 'admin',  fn(array $i) => DashboardService::deleteChoreArea(
        Auth::apartmentId(), (int) $i['id']), ['id']],

    // ---- meals -----------------------------------------------------------
    'meal.week'         => ['GET',  'auth',   fn(array $i) => MealService::week(
        Auth::apartmentId(), (string) ($i['date'] ?? DutyScheduler::today()), (int) Auth::id())],
    'meal.set_menu'     => ['POST', 'auth',   fn(array $i) => MealService::setMenu(
        (int) $i['meal_id'], (int) Auth::id(), Auth::isAdmin(), $i['title'] ?? null, $i['notes'] ?? null), ['meal_id']],
    'meal.suggest'      => ['POST', 'auth',   fn(array $i) => MealService::suggest(
        (int) $i['meal_id'], (int) Auth::id(), (string) $i['title'], $i['notes'] ?? null, (float) ($i['estimated_cost'] ?? 0)), ['meal_id', 'title']],
    'meal.vote'         => ['POST', 'auth',   fn(array $i) => MealService::vote(
        (int) $i['suggestion_id'], (int) Auth::id(), (int) $i['vote']), ['suggestion_id', 'vote']],
    'meal.accept'       => ['POST', 'auth',   fn(array $i) => MealService::acceptSuggestion(
        (int) $i['suggestion_id'], (int) Auth::id(), Auth::isAdmin()), ['suggestion_id']],
    'meal.cook'         => ['POST', 'auth',   fn(array $i) => MealService::assignCook(
        (int) $i['meal_id'], (int) $i['user_id']), ['meal_id', 'user_id']],
    'meal.respond'      => ['POST', 'auth',   fn(array $i) => MealService::setParticipation(
        (int) $i['meal_id'], (int) ($i['user_id'] ?? (int) Auth::id()), (string) $i['status']), ['meal_id', 'status']],
    'meal.bulk_respond' => ['POST', 'auth',   fn(array $i) => MealService::bulkRespond(
        Auth::apartmentId(), (int) Auth::id(), (string) $i['status'],
        (string) ($i['scope'] ?? 'week'), $i['date'] ?? null), ['status']],
    'meal.grocery'      => ['GET',  'auth',   fn(array $i) => MealService::groceryList(
        Auth::apartmentId(), (string) ($i['date'] ?? DutyScheduler::today()))],
    'meal.set_status'   => ['POST', 'admin',  fn(array $i) => MealService::setStatus(
        (int) $i['plan_id'], (string) $i['status'], (int) Auth::id()), ['plan_id', 'status']],

    // ---- expenses --------------------------------------------------------
'expense.list' => ['GET',  'auth',   fn(array $i) => ExpenseService::listFor(
                    Auth::apartmentId(),
                    array_filter($i, static fn($v, $k) => $v !== null && $v !== '',
                        ARRAY_FILTER_USE_BOTH) + ['viewer_id' => Auth::id()],
                    (int) ($i['limit'] ?? 25), (int) ($i['offset'] ?? 0))],
'expense.count' => ['GET',  'auth',   fn(array $i) => ['total' => ExpenseService::countFor(
                    Auth::apartmentId(),
                    array_filter($i, static fn($v, $k) => $v !== null && $v !== '',
                        ARRAY_FILTER_USE_BOTH) + ['viewer_id' => Auth::id()])]],
    'expense.find'      => ['GET',  'auth',   fn(array $i) => ExpenseService::find((int) $i['id']), ['id']],
    'expense.create'    => ['POST', 'auth',   fn(array $i) => ExpenseService::create(
        Auth::apartmentId(), (int) Auth::id(), $i), ['title', 'amount', 'paid_by_user_id']],
    'expense.delete'    => ['POST', 'auth',   function (array $i): array {
        ExpenseService::delete((int) $i['id'], (int) Auth::id());
        return ['deleted' => true];
    }, ['id']],
    'expense.dispute'   => ['POST', 'auth',   fn(array $i) => ExpenseService::flagDispute(
        (int) $i['id'], (int) Auth::id(), $i['note'] ?? null, Auth::isAdmin()), ['id']],
    'expense.categories'=> ['GET',  'auth',   fn() => ExpenseService::categories(Auth::apartmentId())],
    'expense.audit'     => ['GET',  'admin',  fn() => BalanceEngine::audit(Auth::apartmentId())],
    'report.summary'    => ['GET',  'admin',  fn() => BalanceEngine::report(Auth::apartmentId())],

    // ---- shared house fund -------------------------------------------------
    // Any resident can hand money in and see what the pot is doing; only an
    // admin can rewind someone else's entry. The pot is deliberately not part
    // of balance.ledger: fund-paid expenses are excluded from vw_balance_sheet
    // so the two ledgers can never disagree about who owes whom.
    'fund.summary'      => ['GET',  'auth',   fn() => ContributionService::summary(Auth::apartmentId())],
    'contribution.list' => ['GET',  'auth',   fn(array $i) => ContributionService::listFor(
        Auth::apartmentId(), (int) ($i['limit'] ?? 50))],
    'contribution.create' => ['POST', 'auth', fn(array $i) => ContributionService::create(
        Auth::apartmentId(), (int) Auth::id(), $i), ['from_user_id', 'amount']],
    'contribution.delete' => ['POST', 'auth', function (array $i): array {
        ContributionService::delete((int) $i['id'], (int) Auth::id());
        return ['deleted' => true];
    }, ['id']],

    // ---- ledger / settlement --------------------------------------------
    'balance.ledger'    => ['GET',  'auth',   fn(array $i) => BalanceEngine::ledger(
        Auth::apartmentId(), (string) ($i['strategy'] ?? 'auto'))],
    'balance.summary'   => ['GET',  'auth',   fn(array $i) => BalanceEngine::summary(
        Auth::apartmentId(), $i['month'] ?? null)],
    'balance.statement' => ['GET',  'auth',   fn(array $i) => BalanceEngine::statementFor(
        Auth::apartmentId(), (int) ($i['user_id'] ?? (int) Auth::id()))],
    'balance.categories'=> ['GET',  'auth',   fn(array $i) => BalanceEngine::categoryBreakdown(
        Auth::apartmentId(), $i['month'] ?? null)],
    'balance.simplify'  => ['GET',  'auth',   fn(array $i) => DebtSimplifier::simplify(
        array_map('intval', (array) ($i['balances'] ?? [])),
        (string) ($i['strategy'] ?? 'auto'))],
    'balance.settle'    => ['POST', 'auth',   fn(array $i) => ExpenseService::settle(
        Auth::apartmentId(), (int) Auth::id(), $i), ['from_user_id', 'to_user_id', 'amount']],
    'balance.settlements' => ['GET','auth',   fn(array $i) => ExpenseService::settlementsFor(
        Auth::apartmentId(), (int) ($i['limit'] ?? 40))],

    // ---- residents -------------------------------------------------------
    'resident.list'     => ['GET',  'auth',   fn() => ResidentService::all(Auth::apartmentId())],
    'resident.find'     => ['GET',  'auth',   fn(array $i) => ResidentService::find(
        Auth::apartmentId(), (int) $i['id']), ['id']],
    'resident.roster'   => ['GET',  'auth',   fn() => ResidentService::roster(Auth::apartmentId())],
    'resident.update'   => ['POST', 'admin',  fn(array $i) => ResidentService::update(
        Auth::apartmentId(), (int) $i['id'], (int) Auth::id(), $i), ['id']],
    'resident.invite'   => ['POST', 'admin',  fn(array $i) => ResidentService::invite(
        Auth::apartmentId(), (int) Auth::id(), $i), ['email']],
    'resident.invites'  => ['GET',  'admin',  fn() => ResidentService::invites(Auth::apartmentId())],
    'resident.revoke'   => ['POST', 'admin',  function (array $i): array {
        ResidentService::revokeInvite(Auth::apartmentId(), (int) $i['id']);
        return ['revoked' => true];
    }, ['id']],
    'resident.accept'   => ['POST', 'public', fn(array $i) => ResidentService::acceptInvite(
        (string) $i['token'], (string) $i['password'], (string) $i['full_name']),
        ['token', 'password', 'full_name']],
    'resident.offboard' => ['POST', 'admin',  fn(array $i) => ResidentService::completeOffboarding(
        Auth::apartmentId(), (int) $i['user_id'], (int) Auth::id(), !empty($i['force'])), ['user_id']],
    'resident.offboard_start' => ['POST', 'admin', fn(array $i) => ResidentService::beginOffboarding(
        Auth::apartmentId(), (int) $i['user_id'], (int) Auth::id()), ['user_id']],
    'resident.offboard_view'  => ['GET', 'auth', fn(array $i) => ResidentService::offboarding(
        Auth::apartmentId(), (int) ($i['user_id'] ?? (int) Auth::id()))],
    'resident.checklist_toggle' => ['POST', 'admin', function (array $i): array {
        ResidentService::toggleChecklistItem(Auth::apartmentId(), (int) $i['id'], !empty($i['done']));
        return ['ok' => true];
    }, ['id']],
    'resident.reinstate'=> ['POST', 'admin',  fn(array $i) => ResidentService::reinstate(
        Auth::apartmentId(), (int) $i['user_id'],
        isset($i['room_id']) ? (int) $i['room_id'] : null,
        isset($i['duty_group_id']) ? (int) $i['duty_group_id'] : null), ['user_id']],

    // ---- notice board ----------------------------------------------------
    'notice.list'       => ['GET',  'auth',   fn(array $i) => NoticeBoard::feed(
        Auth::apartmentId(), (int) Auth::id(), (int) ($i['limit'] ?? 30))],
    'notice.find'       => ['GET',  'auth',   fn(array $i) => NoticeBoard::find(
        Auth::apartmentId(), (int) $i['id'], (int) Auth::id()), ['id']],
    'notice.create'     => ['POST', 'auth',   fn(array $i) => NoticeBoard::create(
        Auth::apartmentId(), (int) Auth::id(), $i), ['title']],
    'notice.pin'        => ['POST', 'admin',  fn(array $i) => NoticeBoard::pin(
        Auth::apartmentId(), (int) $i['id'], !empty($i['pinned']), $i['until'] ?? null), ['id']],
    'notice.delete'     => ['POST', 'auth',   function (array $i): array {
        NoticeBoard::remove(Auth::apartmentId(), (int) $i['id'], (int) Auth::id());
        return ['deleted' => true];
    }, ['id']],
    'notice.read'       => ['POST', 'auth',   function (array $i): array {
        NoticeBoard::markRead(Auth::apartmentId(), (int) $i['id'], (int) Auth::id());
        return ['ok' => true];
    }, ['id']],
    'notice.read_all'   => ['POST', 'auth',   fn() => ['marked' => NoticeBoard::markAllRead(
        Auth::apartmentId(), (int) Auth::id())]],

    // ---- reminders -------------------------------------------------------
    'reminder.inbox'    => ['GET',  'auth',   fn(array $i) => Reminder::inbox(
        Auth::apartmentId(), (int) Auth::id(), (int) ($i['limit'] ?? 20))],
    'reminder.read'     => ['POST', 'auth',   fn(array $i) => ['ok' => Reminder::markRead(
        (int) $i['id'], (int) Auth::id())]],
    'reminder.read_all' => ['POST', 'auth',   fn() => ['marked' => Reminder::markAllRead(
        Auth::apartmentId(), (int) Auth::id())]],

    // ---- diagnostics -----------------------------------------------------
    // The point of these is that a broken page can still explain itself.
    // `diag` needs a session (it exposes row counts), so diag.php in the web
    // root offers the redacted subset to an anonymous visitor -- a broken login
    // page is exactly when that is needed.
    'diag'             => ['GET',  'auth',   fn() => Diag::report(true)],
    'diag.summary'     => ['GET',  'auth',   fn() => ['summary' => Diag::summary(Diag::report(true))]],
    'diag.log'         => ['GET',  'auth',   fn(array $i) => Diag::recent((int) ($i['limit'] ?? 40))],
    'diag.clear'       => ['POST', 'auth',   fn() => (Diag::clear() ? ['cleared' => true] : ['cleared' => false])],
    // Browser-side errors, pushed server-side so they survive a page reload.
    'diag.submit'      => ['POST', 'auth',   function (array $i): array {
        $entries = is_array($i['entries'] ?? null) ? $i['entries'] : [];
        $kept    = 0;
        foreach (array_slice($entries, -30) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            // A misbehaving client can submit strings that look large. Only a
            // small, whitelisted set of keys ever reach the ring log.
            $allowed = ['kind', 'message', 'url', 'line', 'status', 'body', 'stack', 'action', 'code', 'details', 'file', 'column', 'count'];
            $entry   = array_intersect_key($entry, array_flip($allowed));

            Diag::record(
                'client:' . mb_substr((string) ($entry['kind'] ?? 'js'), 0, 20),
                (string) ($entry['message'] ?? '(no message)'),
                [
                    'url'    => (string) ($entry['url'] ?? ''),
                    'line'   => $entry['line'] ?? null,
                    'status' => $entry['status'] ?? null,
                    'body'   => mb_substr((string) ($entry['body'] ?? ''), 0, 600),
                    'stack'  => mb_substr((string) ($entry['stack'] ?? ''), 0, 900),
                ]
            );
            $kept++;
        }
        return ['stored' => $kept];
    }, ['entries']],

    /* ---------------------------------------------------------------- */
    /*  My profile                       Self-service: any signed-in user  */
    /* ---------------------------------------------------------------- */

    'me.update'   => ['POST', 'auth', fn(array $i) => ResidentService::updateSelf(
        Auth::apartmentId(), (int) Auth::id(), $i)],
    'me.password' => ['POST', 'auth', fn(array $i) => ResidentService::changePassword(
        Auth::apartmentId(), (int) Auth::id(), $i), ['new_password']],

    /* ---------------------------------------------------------------- */
    /*  Roles                             role.manage, or any active admin    */
    /* ---------------------------------------------------------------- */

    'role.list' => ['GET', 'admin', function (): array {
        if (!Auth::can('role.manage')) {
            throw new ValidationException(['role' => 'You cannot manage roles.']);
        }
        return ['roles' => RoleService::listFor(Auth::apartmentId())];
    }],
    'role.users' => ['GET', 'admin', function (): array {
        if (!Auth::can('role.manage')) {
            throw new ValidationException(['role' => 'You cannot manage roles.']);
        }
        return ['users' => RoleService::assignableUsers(Auth::apartmentId())];
    }],
    'role.create' => ['POST', 'admin', function (array $i): array {
        if (!Auth::can('role.manage')) {
            throw new ValidationException(['role' => 'You cannot manage roles.']);
        }
        return RoleService::create(Auth::apartmentId(), (int) Auth::id(), $i);
    }, ['name']],
    'role.update' => ['POST', 'admin', function (array $i): array {
        if (!Auth::can('role.manage')) {
            throw new ValidationException(['role' => 'You cannot manage roles.']);
        }
        return RoleService::update(Auth::apartmentId(), (int) $i['id'], $i);
    }, ['id']],
    'role.delete' => ['POST', 'admin', function (array $i): array {
        if (!Auth::can('role.manage')) {
            throw new ValidationException(['role' => 'You cannot manage roles.']);
        }
        RoleService::delete(Auth::apartmentId(), (int) Auth::id(), (int) $i['id']);
        return ['ok' => true];
    }, ['id']],
    'role.assign' => ['POST', 'admin', function (array $i): array {
        if (!Auth::can('role.manage')) {
            throw new ValidationException(['role' => 'You cannot manage roles.']);
        }
        return RoleService::assign(
            Auth::apartmentId(), (int) $i['user_id'], $i['role_id'] ?? null
        );
    }, ['user_id']],

    /* ---------------------------------------------------------------- */
    /*  Selective data reset               data.purge, or any active admin    */
    /* ---------------------------------------------------------------- */

    'data.reset_scopes' => ['GET', 'admin', function (): array {
        if (!Auth::can('data.purge')) {
            throw new ValidationException(['reset' => 'You cannot reset household data.']);
        }
        return [
            'scopes'      => DataResetService::catalogue(Auth::apartmentId(), (int) Auth::id()),
            'confirm'     => DataResetService::CONFIRM_PHRASE,
            'residents'   => DataResetService::removableResidentCount(
                Auth::apartmentId(), (int) Auth::id()
            ),
        ];
    }],
    'data.reset_preview' => ['POST', 'admin', function (array $i): array {
        if (!Auth::can('data.purge')) {
            throw new ValidationException(['reset' => 'You cannot reset household data.']);
        }
        return DataResetService::preview(
            Auth::apartmentId(), (int) Auth::id(), (array) ($i['scopes'] ?? [])
        );
    }, ['scopes']],
    'data.reset_run' => ['POST', 'admin', function (array $i): array {
        if (!Auth::can('data.purge')) {
            throw new ValidationException(['reset' => 'You cannot reset household data.']);
        }
        return DataResetService::purge(
            Auth::apartmentId(),
            (int) Auth::id(),
            (array) ($i['scopes'] ?? []),
            (string) ($i['confirm'] ?? '')
        );
    }, ['scopes']],
];

/* -------------------------------------------------------------------------- */
/*  Dispatch                                                                  */
/* -------------------------------------------------------------------------- */
try {
    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

    if ($action === '' || !isset($ROUTES[$action])) {
        Response::error(
            'Unknown endpoint: ' . $action,
            404,
            'not_found',
            ['available' => array_keys($ROUTES)]
        );
    }

    [$method, $guard, $handler, $required] = array_pad($ROUTES[$action], 4, []);

    // ---- method check ---------------------------------------------------
    $verb = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($verb === 'POST' && ($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? '') !== '') {
        $verb = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
    }
    if ($method !== 'ANY' && $verb !== $method) {
        Response::error("This endpoint only accepts $method.", 405, 'method_not_allowed');
    }

    // ---- input ----------------------------------------------------------
    $input = array_merge($_GET, $_POST);
    $raw   = file_get_contents('php://input') ?: '';
    if ($raw !== '' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $input = array_merge($input, $decoded);
        }
    }

    // ---- auth -----------------------------------------------------------
    if ($guard !== 'public') {
        enforce_session_lifetime(true);
        if (ApiAuth::resolve()) {
            if (Auth::isAdmin() === false && $guard === 'admin') {
                Response::error('This endpoint is admin-only.', 403, 'forbidden');
            }
        } else {
            Response::error('Sign in to use this endpoint.', 401, 'unauthenticated');
        }
    }

    // CSRF on mutating requests (cookie-auth only)
    if ($verb === 'POST' && $guard !== 'public' && !ApiAuth::usedBearerToken()) {
        $token = $input['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!csrf_valid(is_string($token) ? $token : null)) {
            Response::error('CSRF token missing or expired. Reload the page.', 419, 'csrf_failed');
        }
    }

    // ---- required fields -------------------------------------------------
    foreach ($required as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            Response::error("Missing required field: $field", 422, 'validation_failed',
                [$field => 'This field is required.']);
        }
    }

    // ---- run -------------------------------------------------------------
    $data = $handler($input);

    // Optional algorithm self-test on read endpoints
    if (isset($_GET['verify']) && $_GET['verify'] !== '' && $guard !== 'public') {
        $data = ['result' => $data, 'verification' => SelfTest::run()];
    }

    Response::json($data);

} catch (ValidationException $e) {
    Response::error($e->getMessage(), 422, 'validation_failed', $e->errors);

} catch (RuntimeException | InvalidArgumentException $e) {
    // A 400 from here is usually a data invariant, not a client mistake, so the
    // ring log keeps the stack even though the browser is only told the message.
    Diag::record('api', $e->getMessage(), [
        'type'   => $e::class,
        'file'   => $e->getFile() . ':' . $e->getLine(),
        'trace'  => explode("\n", $e->getTraceAsString()),
    ]);
    Response::error($e->getMessage(), 400, 'bad_request', [], false);

} catch (PDOException $e) {
    error_log('[FlatMate][api] ' . $e->getMessage());
    Diag::record('pdo', $e->getMessage(), [
        'action'  => $action,
        'sqlstate' => $e->getCode(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
    ]);
    $dev = config('app.env', 'local') !== 'production';
    Response::error(
        $dev ? $e->getMessage() : 'A database error occurred.',
        500,
        'database_error',
        [],
        false
    );

} catch (Throwable $e) {
    error_log('[FlatMate][api] ' . $e->getMessage());
    Diag::record('throwable', $e->getMessage(), [
        'type' => $e::class,
        'file' => $e->getFile() . ':' . $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString()),
    ]);
    $dev = config('app.env', 'local') !== 'production';
    Response::error(
        $dev ? $e->getMessage() : 'Unexpected server error.',
        500,
        'server_error',
        [],
        false
    );
}
