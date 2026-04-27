<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php?tab=login");
    exit();
}

require_once 'db.php';

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$cart = $_SESSION['cart'];

/*
|--------------------------------------------------------------------------
| Handle cart actions first
|--------------------------------------------------------------------------
*/
if (isset($_GET['action'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    $action = $_GET['action'];

    if (isset($cart[$id])) {
        if ($action === 'inc') {
            $cart[$id]['qty'] = max(1, (int)($cart[$id]['qty'] ?? 1) + 1);
        } elseif ($action === 'dec') {
            $cart[$id]['qty'] = max(1, (int)($cart[$id]['qty'] ?? 1) - 1);
        } elseif ($action === 'remove') {
            unset($cart[$id]);
        }
    }

    $_SESSION['cart'] = $cart;
    header("Location: cart.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Handle promo code apply
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_discount'])) {
    $promoCodeInput = strtoupper(trim($_POST['promo_code'] ?? ''));
    $_SESSION['promo_code'] = $promoCodeInput;
    header("Location: cart.php");
    exit();
}

$cart = $_SESSION['cart'] ?? [];

if (!is_array($cart)) {
    $cart = [];
}

/*
|--------------------------------------------------------------------------
| Recalculate everything from DB (SR6)
|--------------------------------------------------------------------------
*/
$subtotal = 0.00;
$deliveryMap = [];
$etaMap = [];

foreach ($cart as $id => $item) {
    $menuItemId = (int)($item['id'] ?? 0);
    $qty = max(1, (int)($item['qty'] ?? 1));

    $stmt = $pdo->prepare("
        SELECT 
            m.id,
            m.price,
            r.delivery_fee,
            r.eta,
            r.id AS restaurant_id
        FROM menu_items m
        JOIN restaurants r ON m.restaurant_id = r.id
        WHERE m.id = ?
        LIMIT 1
    ");
    $stmt->execute([$menuItemId]);
    $dbItem = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dbItem) {
        unset($cart[$id]);
        continue;
    }

    $addonTotal = 0.00;
    $selectedAddons = $item['addons'] ?? [];

    if (is_array($selectedAddons) && !empty($selectedAddons)) {
        $addonIds = [];

        foreach ($selectedAddons as $addon) {
            $addonId = (int)($addon['id'] ?? 0);
            if ($addonId > 0) {
                $addonIds[] = $addonId;
            }
        }

        $addonIds = array_values(array_unique($addonIds));

        if (!empty($addonIds)) {
            $placeholders = implode(',', array_fill(0, count($addonIds), '?'));

            $addonQuery = "
                SELECT id, addon_name, addon_price
                FROM menu_item_addons
                WHERE menu_item_id = ?
                  AND is_available = 1
                  AND id IN ($placeholders)
            ";

            $params = array_merge([$menuItemId], $addonIds);
            $addonStmt = $pdo->prepare($addonQuery);
            $addonStmt->execute($params);
            $dbAddons = $addonStmt->fetchAll(PDO::FETCH_ASSOC);

            $foundAddonIds = [];
            $trustedAddons = [];

            foreach ($dbAddons as $dbAddon) {
                $foundAddonIds[] = (int)$dbAddon['id'];
                $addonTotal += (float)$dbAddon['addon_price'];
                $trustedAddons[] = [
                    'id' => (int)$dbAddon['id'],
                    'name' => $dbAddon['addon_name'],
                    'price' => (float)$dbAddon['addon_price']
                ];
            }

            sort($addonIds);
            sort($foundAddonIds);

            if ($addonIds === $foundAddonIds) {
                $cart[$id]['addons'] = $trustedAddons;
            } else {
                $cart[$id]['addons'] = [];
                $addonTotal = 0.00;
            }
        } else {
            $cart[$id]['addons'] = [];
        }
    } else {
        $cart[$id]['addons'] = [];
    }

    $restaurantId = (int)$dbItem['restaurant_id'];

    $cart[$id]['qty'] = $qty;
    $cart[$id]['price'] = (float)$dbItem['price'];
    $cart[$id]['delivery_fee'] = (float)$dbItem['delivery_fee'];
    $cart[$id]['eta'] = $dbItem['eta'] ?? '30-40 min';
    $cart[$id]['restaurant_id'] = $restaurantId;
    $cart[$id]['addon_total'] = $addonTotal;

    $unitPrice = (float)$dbItem['price'] + $addonTotal;
    $subtotal += $unitPrice * $qty;

    if (!isset($deliveryMap[$restaurantId])) {
        $deliveryMap[$restaurantId] = (float)$dbItem['delivery_fee'];
        $etaMap[$restaurantId] = $dbItem['eta'] ?? '30-40 min';
    }
}

$_SESSION['cart'] = $cart;

/*
|--------------------------------------------------------------------------
| Delivery fee
|--------------------------------------------------------------------------
*/
$deliveryFee = array_sum($deliveryMap);

/*
|--------------------------------------------------------------------------
| Promo logic (STRICT server-side)
|--------------------------------------------------------------------------
*/
$promoCode = strtoupper(trim($_SESSION['promo_code'] ?? ''));
$discountAmount = 0.00;

if ($promoCode !== '') {
    if ($promoCode === 'MAKAN5') {
        $discountAmount = 5.00;
    } elseif ($promoCode === 'SAVE10') {
        $discountAmount = round($subtotal * 0.10, 2);
    } elseif ($promoCode === 'WELCOME') {
        $discountAmount = 3.00;
    } else {
        $_SESSION['promo_code'] = '';
        $promoCode = '';
        $discountAmount = 0.00;
    }
}

if ($discountAmount > $subtotal) {
    $discountAmount = $subtotal;
}

/*
|--------------------------------------------------------------------------
| Final total
|--------------------------------------------------------------------------
*/
$finalTotal = $subtotal + $deliveryFee - $discountAmount;

if ($finalTotal < 0) {
    $finalTotal = 0.00;
}

$_SESSION['subtotal'] = $subtotal;
$_SESSION['delivery_fee'] = $deliveryFee;
$_SESSION['discount_amount'] = $discountAmount;
$_SESSION['final_total'] = $finalTotal;
$_SESSION['order_eta'] = !empty($etaMap) ? implode(', ', $etaMap) : '30-40 min';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Cart - MakanNow</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .cart-wrap {
            background: white;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }

        .cart-item {
            display: grid;
            grid-template-columns: 100px 1fr auto;
            gap: 16px;
            align-items: center;
            padding: 18px 0;
            border-bottom: 1px solid #eee;
        }

        .cart-item img {
            width: 100px;
            height: 80px;
            object-fit: cover;
            border-radius: 12px;
        }

        .qty-row {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .qty-btn {
            width: 34px;
            height: 34px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: #ffcc00;
            color: #1f1f1f;
            font-weight: 800;
            text-decoration: none;
        }

        .cart-summary {
            margin-top: 28px;
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 24px;
            align-items: start;
        }

        .promo-box,
        .summary-box {
            background: #fafafa;
            border: 1px solid #eee;
            border-radius: 16px;
            padding: 20px;
        }

        .promo-form {
            display: flex;
            gap: 10px;
            margin-top: 12px;
        }

        .promo-input {
            flex: 1;
            padding: 12px 14px;
            border: 1px solid #ddd;
            border-radius: 10px;
            outline: none;
        }

        .summary-line {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            color: #444;
        }

        .summary-line.total {
            font-size: 22px;
            font-weight: 800;
            color: #1f1f1f;
            border-top: 1px solid #ddd;
            padding-top: 14px;
            margin-top: 14px;
        }

        .discount-text {
            color: #1e8e3e;
            font-weight: 700;
        }

        .message-box {
            margin-top: 12px;
            font-size: 14px;
            font-weight: 600;
            color: #666;
        }

        .empty-cart {
            background: white;
            border-radius: 18px;
            padding: 40px;
            text-align: center;
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }

        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 18px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        @media (max-width: 900px) {
            .cart-item {
                grid-template-columns: 1fr;
            }

            .cart-summary {
                grid-template-columns: 1fr;
            }

            .promo-form {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

<?php include 'header.php'; ?>

<section class="section">
    <div class="container">
        <h2 class="section-title">My Cart</h2>

        <?php if (empty($cart)): ?>
            <div class="empty-cart">
                <h3>Your basket is empty</h3>
                <p style="margin:10px 0 20px;color:#666;">Start adding delicious food from the dashboard.</p>
                <a href="dashboard.php" class="btn btn-primary">Go to Home</a>
            </div>
        <?php else: ?>
            <div class="cart-wrap">
                <?php foreach ($cart as $id => $item): ?>
                    <?php
                    $itemName = $item['name'] ?? 'Unknown Item';
                    $imageUrl = $item['image_url'] ?? '';
                    $restaurantName = $item['restaurant_name'] ?? 'Unknown Restaurant';
                    $price = (float)($item['price'] ?? 0);
                    $addonTotal = (float)($item['addon_total'] ?? 0);
                    $qty = max(1, (int)($item['qty'] ?? 1));
                    $addons = (isset($item['addons']) && is_array($item['addons'])) ? $item['addons'] : [];
                    $realItemId = (int)($item['id'] ?? $id);
                    ?>
                    <div class="cart-item">
                        <img src="<?= htmlspecialchars($imageUrl) ?>" alt="<?= htmlspecialchars($itemName) ?>">

                        <div>
                            <h3><?= htmlspecialchars($itemName) ?></h3>
                            <p style="color:#666; margin-top:6px;"><?= htmlspecialchars($restaurantName) ?></p>

                            <p style="margin-top:8px; font-weight:700;">
                                RM <?= number_format($price, 2) ?>
                                <?php if ($addonTotal > 0): ?>
                                    + Add-ons RM <?= number_format($addonTotal, 2) ?>
                                <?php endif; ?>
                            </p>

                            <?php if (!empty($addons)): ?>
                                <div style="margin-top:10px; color:#666; font-size:14px;">
                                    <strong>Selected Add-ons:</strong>
                                    <?php foreach ($addons as $addon): ?>
                                        <div>
                                            • <?= htmlspecialchars($addon['name'] ?? 'Add-on') ?>
                                            (+ RM <?= number_format((float)($addon['price'] ?? 0), 2) ?>)
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($item['notes'])): ?>
                                <div style="margin-top:10px; color:#666; font-size:14px;">
                                    <strong>Note:</strong> <?= htmlspecialchars($item['notes']) ?>
                                </div>
                            <?php endif; ?>

                            <div class="qty-row">
                                <a href="cart.php?action=dec&id=<?= (int)$id ?>" class="qty-btn">-</a>
                                <span><?= $qty ?></span>
                                <a href="cart.php?action=inc&id=<?= (int)$id ?>" class="qty-btn">+</a>

                                <a href="cart.php?action=remove&id=<?= (int)$id ?>" style="margin-left:10px; color:#d93025; font-weight:700;">
                                    Remove
                                </a>

                                <a href="customize_item.php?item_id=<?= $realItemId ?>&from=cart" style="margin-left:10px; color:#c99700; font-weight:700;">
                                    Edit Order
                                </a>
                            </div>
                        </div>

                        <div style="font-weight:800; font-size:20px;">
                            RM <?= number_format(($price + $addonTotal) * $qty, 2) ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="cart-summary">
                    <div class="promo-box">
                        <h3>Discount Code</h3>
                        <p style="color:#666; margin-top:8px;">
                            Try: <b>MAKAN5</b>, <b>SAVE10</b>, or <b>WELCOME</b>
                        </p>

                        <form method="POST" class="promo-form">
                            <input
                                type="text"
                                name="promo_code"
                                class="promo-input"
                                placeholder="Enter promo code"
                                value="<?= htmlspecialchars($promoCode) ?>"
                            >
                            <button type="submit" name="apply_discount" class="btn btn-primary">Apply</button>
                        </form>

                        <?php if (!empty($message)): ?>
                            <div class="message-box"><?= htmlspecialchars($message) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="summary-box">
                        <h3 style="margin-bottom:16px;">Order Summary</h3>

                        <div class="summary-line">
                            <span>Subtotal</span>
                            <span>RM <?= number_format($subtotal, 2) ?></span>
                        </div>

                        <div class="summary-line">
                            <span>Delivery Fee</span>
                            <span>RM <?= number_format($deliveryFee, 2) ?></span>
                        </div>

                        <div class="summary-line">
                            <span>Discount</span>
                            <span class="discount-text">- RM <?= number_format($discountAmount, 2) ?></span>
                        </div>

                        <div class="summary-line total">
                            <span>Total</span>
                            <span>RM <?= number_format($finalTotal, 2) ?></span>
                        </div>

                        <div class="action-buttons">
                            <a href="dashboard.php" class="btn btn-outline">Continue Shopping</a>
                            <a href="payment.php" class="btn btn-primary">Proceed to Payment</a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

</body>
</html>