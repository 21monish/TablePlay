<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class HelpAssistantService
{
    public function answer(string $message, User $user): array
    {
        $role = $user->role?->name ?? 'staff';
        $normalized = $this->normalize($message);

        $match = collect($this->topics())
            ->filter(fn (array $topic) => in_array($role, $topic['roles'], true))
            ->map(fn (array $topic) => $topic + ['score' => $this->score($normalized, $topic['keywords'])])
            ->sortByDesc('score')
            ->first(fn (array $topic) => $topic['score'] > 0);

        $topic = $match ?: $this->fallback($role);

        return [
            'title' => $topic['title'],
            'reply' => $topic['answer'],
            'steps' => $topic['steps'] ?? [],
            'action' => isset($topic['route']) ? [
                'label' => $topic['action'],
                'url' => route($topic['route']),
            ] : null,
            'suggestions' => $topic['suggestions'] ?? $this->suggestions($role),
            'offline' => true,
        ];
    }

    public function suggestions(string $role): array
    {
        return match ($role) {
            'admin' => ['Pair a tablet', 'Add a menu item', 'Check system health', 'Create staff account'],
            'counter' => ['Confirm an order', 'Generate a bill', 'Handle waiter request', 'Extend game time'],
            'kitchen' => ['Start preparing an order', 'Mark food ready', 'Enable kitchen sound', 'What does overdue mean?'],
            'waiter' => ['Handle a guest request', 'Serve a ready order', 'Open a table visit', 'Request a bill'],
            default => ['How do I get started?', 'How does TablePlay work?'],
        };
    }

    private function topics(): array
    {
        return [
            [
                'title' => 'Getting started',
                'roles' => ['admin', 'counter', 'kitchen', 'waiter'],
                'keywords' => ['get started', 'how to use', 'what can i do', 'help me', 'tableplay work', 'first step', 'guide'],
                'answer' => 'TablePlay follows the restaurant journey from tablet order to kitchen preparation, counter billing, and table closure. Your sidebar only shows the tools allowed for your staff role.',
                'steps' => ['Choose your workspace from the left navigation.', 'Watch status badges for items that need action.', 'Use this helper whenever you are unsure about a button or workflow.'],
            ],
            [
                'title' => 'Waiter floor workflow',
                'roles' => ['admin', 'waiter'],
                'keywords' => ['waiter floor', 'handle request', 'acknowledge request', 'serve ready order', 'open table visit', 'request bill', 'water request'],
                'answer' => 'The Waiter workspace brings guest calls, ready food, and active tables together. Accept each request, complete it after service, and keep order and table status accurate.',
                'steps' => ['Acknowledge a guest request when you take responsibility.', 'Complete it only after serving the guest.', 'Collect Ready orders and mark them Served.', 'Open an available table visit or notify Counter when a table requests its bill.'],
                'suggestions' => ['Serve a ready order', 'Open a table visit', 'Request a bill'],
            ],
            [
                'title' => 'Tablet pairing',
                'roles' => ['admin'],
                'keywords' => ['pair tablet', 'pair device', 'tablet setup', 'assign tablet', 'device code', 'registration code', 'table phone'],
                'answer' => 'Use the Setup guide to generate a secure one-time QR for the table, then scan it from the Customer app. The server address and table assignment are saved automatically.',
                'steps' => ['Open Setup guide or Tables & devices in Admin.', 'Choose an unpaired table and select Pair QR.', 'On the Customer app, select Scan pairing QR.', 'Scan within 15 minutes. The QR expires after one successful pairing.'],
                'route' => 'admin.tables',
                'action' => 'Open Tables & devices',
                'suggestions' => ['Add a new table', 'Tablet shows offline', 'How does customer ordering work?'],
            ],
            [
                'title' => 'Tables and devices',
                'roles' => ['admin'],
                'keywords' => ['add table', 'new table', 'table capacity', 'disable table', 'delete table', 'remove table', 'archive table', 'unpair', 'tables devices'],
                'answer' => 'Use Tables & devices to create, edit, disable, archive, or safely delete dining tables. Unused tables are deleted permanently; tables with service history are archived so reports remain correct.',
                'steps' => ['Create the table with a unique code such as T01.', 'Choose Manage beside a table to change its name, capacity, status, or active state.', 'Choose Delete permanently only for an unused table; a historical table automatically shows Archive instead.', 'Close any active visit before disabling, archiving, or deleting the table.'],
                'route' => 'admin.tables',
                'action' => 'Manage tables',
            ],
            [
                'title' => 'Menu management',
                'roles' => ['admin'],
                'keywords' => ['menu item', 'add food', 'add category', 'price', 'availability', 'sold out', 'preparation time', 'edit menu', 'food type'],
                'answer' => 'Menu categories organize the customer tablet. Each item has a price, food type, preparation target, and live availability switch.',
                'steps' => ['Create a category such as Starters or Drinks.', 'Add the item with its price and expected preparation time.', 'Use the availability control to hide sold-out items immediately.'],
                'route' => 'admin.menu',
                'action' => 'Open Menu',
                'suggestions' => ['Mark an item sold out', 'Change preparation time', 'Configure tax'],
            ],
            [
                'title' => 'Staff and permissions',
                'roles' => ['admin'],
                'keywords' => ['staff account', 'create user', 'team member', 'permission', 'role', 'pin', 'password', 'disable staff', 'kitchen login', 'counter login'],
                'answer' => 'Create one account per staff member and assign the smallest role they need: Admin, Counter, or Kitchen. Disabled accounts cannot sign in.',
                'steps' => ['Open Team and choose the correct role.', 'Set a unique username and secure password; a PIN is optional.', 'Disable an account when the staff member no longer needs access.'],
                'route' => 'admin.team',
                'action' => 'Manage Team',
            ],
            [
                'title' => 'Restaurant configuration',
                'roles' => ['admin'],
                'keywords' => ['settings', 'restaurant name', 'logo', 'brand color', 'gst', 'tax', 'currency', 'timezone', 'receipt footer', 'game duration', 'offline threshold'],
                'answer' => 'Restaurant settings control the visible brand, receipt details, tax and currency rules, timezone, kitchen refresh speed, device health threshold, and default game duration.',
                'steps' => ['Open Restaurant settings.', 'Update identity, tax, receipt, or operating values.', 'Choose Save all settings; every configuration change is audited.'],
                'route' => 'admin.settings',
                'action' => 'Open Restaurant settings',
                'suggestions' => ['Change brand color', 'Configure game time', 'Check system health'],
            ],
            [
                'title' => 'System health',
                'roles' => ['admin'],
                'keywords' => ['system health', 'database status', 'cache', 'storage', 'queue', 'reverb', 'server status', 'diagnostic', 'offline device', 'not connected', 'real time offline'],
                'answer' => 'System health checks every local service needed by TablePlay. Healthy is normal; Degraded usually means Reverb is stopped or a warning needs review; Unhealthy means a core check failed.',
                'steps' => ['Open System health and run the checks again.', 'Review any warning or failed card.', 'For real-time warnings, confirm Reverb is running on port 8080.', 'For an offline tablet, verify the hotspot connection and reopen the app.'],
                'route' => 'admin.system',
                'action' => 'View System health',
            ],
            [
                'title' => 'Reports and audit history',
                'roles' => ['admin'],
                'keywords' => ['report', 'sales', 'revenue', 'average order', 'audit', 'history', 'who changed'],
                'answer' => 'Reports shows sales totals, payment counts, order averages, daily performance, and the latest audited staff actions.',
                'steps' => ['Open Reports.', 'Review sales cards and the daily breakdown.', 'Use audit history to identify who changed an important record.'],
                'route' => 'admin.reports',
                'action' => 'Open Reports',
            ],
            [
                'title' => 'Game catalog',
                'roles' => ['admin'],
                'keywords' => ['add game', 'game catalog', 'enable game', 'disable game', 'player mode', 'game path'],
                'answer' => 'The game catalog controls which games appear on customer tablets. Access itself remains locked until the counter confirms an order.',
                'steps' => ['Open Games and add the game name, path, and player mode.', 'Enable only games ready for customer use.', 'Use Restaurant settings to change the default unlock duration.'],
                'route' => 'admin.games',
                'action' => 'Manage Games',
            ],
            [
                'title' => 'Confirming an order',
                'roles' => ['admin', 'counter'],
                'keywords' => ['confirm order', 'incoming order', 'pending order', 'accept order', 'unlock games', 'new order'],
                'answer' => 'A tablet order first enters the Counter as Pending. Confirming it sends the ticket to Kitchen and unlocks games for that table only.',
                'steps' => ['Verify the table, items, quantities, and guest notes.', 'Choose Confirm & unlock games.', 'The kitchen receives the order and the table game timer starts or restarts.'],
                'route' => 'counter.index',
                'action' => 'Open Counter',
                'suggestions' => ['Reject an order', 'Extend game time', 'Generate a bill'],
            ],
            [
                'title' => 'Rejecting an order',
                'roles' => ['admin', 'counter'],
                'keywords' => ['reject order', 'cancel order', 'cannot prepare', 'rejection reason'],
                'answer' => 'Reject only a Pending order and always enter a clear reason. A rejected order does not reach Kitchen and does not unlock games.',
                'steps' => ['Review the order with the customer if needed.', 'Enter the rejection reason beside the Pending ticket.', 'Choose Reject and confirm the tablet receives the updated status.'],
                'route' => 'counter.index',
                'action' => 'Open Counter',
            ],
            [
                'title' => 'Guest service requests',
                'roles' => ['admin', 'counter'],
                'keywords' => ['waiter request', 'water request', 'bill request', 'guest request', 'service request', 'call waiter', 'request water'],
                'answer' => 'Tablet requests appear in Guest requests. Acknowledge when a staff member accepts the task, then mark it Complete after serving the table.',
                'steps' => ['Find the table and request type.', 'Change the status to Acknowledged.', 'Complete or cancel it when resolved.'],
                'route' => 'counter.index',
                'action' => 'View Guest requests',
            ],
            [
                'title' => 'Billing and payment',
                'roles' => ['admin', 'counter'],
                'keywords' => ['generate bill', 'billing', 'cash payment', 'receive cash', 'receipt', 'discount', 'close table', 'request bill', 'paid'],
                'answer' => 'Generate the bill from the open table session, apply any approved discount, record the received cash, and print the receipt. Completing payment locks games immediately.',
                'steps' => ['Find the correct table under Billing & table closure.', 'Enter a discount only when approved, then generate the bill.', 'Enter cash received and choose Record cash.', 'Open Receipt to print or reprint the customer copy.'],
                'route' => 'counter.index',
                'action' => 'Open Billing',
                'suggestions' => ['Apply a discount', 'Print a receipt', 'Why are games locked?'],
            ],
            [
                'title' => 'Game access and timers',
                'roles' => ['admin', 'counter'],
                'keywords' => ['extend game', 'game time', 'games locked', 'games unlock', 'stop games', 'game timer', 'one hour', '60 minutes'],
                'answer' => 'Game access is controlled by the local server per table. Confirming an order starts or restarts the configured timer; billing, stopping the session, or expiry locks games.',
                'steps' => ['Confirm an eligible order to unlock games.', 'Use Game timers to extend a table when approved.', 'Choose Stop to lock games early.', 'Never change another table while resolving a timer request.'],
                'route' => 'counter.index',
                'action' => 'Manage game timers',
            ],
            [
                'title' => 'Kitchen order workflow',
                'roles' => ['admin', 'kitchen'],
                'keywords' => ['start preparing', 'accept ticket', 'mark ready', 'mark served', 'kitchen order', 'food ready', 'preparing order', 'ticket status'],
                'answer' => 'Kitchen tickets move in one direction: Confirmed → Preparing → Ready → Served. Always read item and guest instructions before starting.',
                'steps' => ['Choose Accept & start preparing on a new ticket.', 'Prepare every item and follow highlighted instructions.', 'Choose Mark ready when the full order can leave Kitchen.', 'Choose Mark served after service collects or delivers it.'],
                'route' => 'kitchen.index',
                'action' => 'Open Kitchen display',
                'suggestions' => ['What does overdue mean?', 'Enable kitchen sound', 'Read customer instructions'],
            ],
            [
                'title' => 'Kitchen timers and sound',
                'roles' => ['admin', 'kitchen'],
                'keywords' => ['overdue', 'late order', 'preparation timer', 'kitchen sound', 'notification sound', 'full screen'],
                'answer' => 'The ticket timer compares elapsed time with the longest item preparation target. A plus sign means the target is overdue. Sound must be enabled once after opening the display because browsers block automatic audio.',
                'steps' => ['Choose Enable sound and listen for the confirmation beep.', 'Use Full screen for a dedicated kitchen monitor.', 'Prioritize overdue tickets while still respecting order sequence and safety.'],
                'route' => 'kitchen.index',
                'action' => 'Open Kitchen display',
            ],
            [
                'title' => 'Customer tablet flow',
                'roles' => ['admin', 'counter', 'kitchen'],
                'keywords' => ['customer ordering', 'tablet order', 'how customer uses', 'customer tablet', 'place order', 'cart', 'party size'],
                'answer' => 'The customer opens a table visit, selects party size, browses the menu, adds items and instructions, then sends the order to Counter. Order progress and service requests update on the tablet.',
                'steps' => ['Confirm the tablet shows the correct table code.', 'Start the visit and select party size.', 'Add items, quantities, and optional instructions to the cart.', 'Place the order and wait for Counter confirmation.'],
            ],
            [
                'title' => 'Local network connection',
                'roles' => ['admin', 'counter', 'kitchen'],
                'keywords' => ['hotspot', 'wifi', 'network', 'cannot connect', 'connection failed', 'server ip', 'localhost', 'phone cannot open'],
                'answer' => 'Every device must use the same hotspot and connect to the laptop IP, not localhost. Your current pilot server address is shown in the app configuration.',
                'steps' => ['Confirm the laptop and phones are on the same hotspot.', 'Open the laptop IP and port 8000 in the phone browser.', 'Keep Laravel on port 8000 and Reverb on port 8080 running.', 'If it still fails, check hotspot client isolation and Windows Firewall.'],
            ],
        ];
    }

    private function fallback(string $role): array
    {
        $roleName = Str::headline($role);

        return [
            'title' => $roleName.' guide',
            'answer' => 'I could not match that question to a TablePlay task yet. Try asking about a screen, status, or action—for example “How do I confirm an order?”',
            'steps' => ['Mention the page or button you are using.', 'Describe the result you want.', 'Choose one of the suggested questions below.'],
            'suggestions' => $this->suggestions($role),
        ];
    }

    private function score(string $message, array $keywords): int
    {
        $score = 0;
        $messageWords = array_filter(explode(' ', $message), fn (string $word) => strlen($word) > 2);

        foreach ($keywords as $keyword) {
            $needle = $this->normalize($keyword);
            if (str_contains($message, $needle)) {
                $score += str_contains($needle, ' ') ? 8 : 4;
                continue;
            }

            $keywordWords = array_filter(explode(' ', $needle), fn (string $word) => strlen($word) > 2);
            $matches = 0;
            foreach ($keywordWords as $keywordWord) {
                $matched = in_array($keywordWord, $messageWords, true);
                if (!$matched && strlen($keywordWord) >= 5) {
                    $matched = collect($messageWords)->contains(
                        fn (string $messageWord) => abs(strlen($messageWord) - strlen($keywordWord)) <= 1
                            && levenshtein($messageWord, $keywordWord) <= 1
                    );
                }
                if ($matched) {
                    $matches++;
                }
            }
            if ($matches >= min(2, count($keywordWords))) {
                $score += $matches;
            }
        }

        return $score;
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', Str::lower(Str::ascii($value)))));
    }
}
