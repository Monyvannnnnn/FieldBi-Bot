<?php
/**
 * FieldBi Supply Chain & Order Fulfillment Interactive Workflow UI
 */

$assetDir = __DIR__ . '/asset';
$workflowImg = 'asset/workflow_diagram.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FieldBi — Supply Chain & Order Fulfillment Interactive Workflow UI</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0b0f19;
            --bg-surface: #111827;
            --bg-card: #1f2937;
            --bg-card-hover: #374151;
            --border-color: #2d3748;
            --text-main: #f9fafb;
            --text-muted: #9ca3af;
            
            --phase-red: #ef4444;
            --phase-red-glow: rgba(239, 68, 68, 0.35);
            --phase-orange: #f97316;
            --phase-orange-glow: rgba(249, 115, 22, 0.35);
            --phase-green: #10b981;
            --phase-green-glow: rgba(16, 185, 129, 0.35);
            --phase-purple: #a855f7;
            --phase-purple-glow: rgba(168, 85, 247, 0.35);
            --phase-pink: #ec4899;
            --phase-pink-glow: rgba(236, 72, 153, 0.35);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        /* Navigation Header */
        header {
            background: linear-gradient(135deg, rgba(31, 41, 55, 0.95), rgba(17, 24, 39, 0.98));
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-color);
            padding: 16px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .brand-container {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-logo {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, #e50914, #b91c1c);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 24px;
            color: #ffffff;
            box-shadow: 0 0 20px var(--phase-red-glow);
        }

        .brand-title h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 20px;
            font-weight: 700;
            color: #ffffff;
        }

        .brand-title p {
            font-size: 12.5px;
            color: var(--text-muted);
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--bg-card);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s;
        }

        .nav-btn:hover {
            background: var(--bg-card-hover);
            border-color: #4b5563;
        }

        .nav-btn.active {
            background: linear-gradient(135deg, #e50914, #c80d17);
            border-color: #e50914;
            color: #ffffff;
            font-weight: 600;
            box-shadow: 0 4px 14px var(--phase-red-glow);
        }

        /* Main Workspace Layout */
        .workspace {
            display: flex;
            flex: 1;
            height: calc(100vh - 75px);
            overflow: hidden;
        }

        /* Sidebar Steps List */
        .sidebar {
            width: 380px;
            background: var(--bg-surface);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            z-index: 20;
        }

        .sidebar-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border-color);
            background: rgba(31, 41, 55, 0.4);
        }

        .sidebar-title {
            font-family: 'Outfit', sans-serif;
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Phase Tabs */
        .phase-tabs {
            display: flex;
            gap: 6px;
            overflow-x: auto;
            padding-bottom: 4px;
            scrollbar-width: none;
        }

        .phase-tabs::-webkit-scrollbar {
            display: none;
        }

        .tab-chip {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 600;
            background: var(--bg-card);
            color: var(--text-muted);
            border: 1px solid var(--border-color);
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
        }

        .tab-chip:hover, .tab-chip.active {
            color: #ffffff;
            border-color: #4b5563;
        }

        .tab-chip.tab-all.active { background: #374151; border-color: #6b7280; }
        .tab-chip.tab-order.active { background: rgba(239, 68, 68, 0.2); border-color: var(--phase-red); color: #fca5a5; }
        .tab-chip.tab-warehouse.active { background: rgba(249, 115, 22, 0.2); border-color: var(--phase-orange); color: #fdba74; }
        .tab-chip.tab-settlement.active { background: rgba(16, 185, 129, 0.2); border-color: var(--phase-green); color: #6ee7b7; }
        .tab-chip.tab-receiving.active { background: rgba(168, 85, 247, 0.2); border-color: var(--phase-purple); color: #d8b4fe; }

        /* Steps Timeline */
        .steps-container {
            flex: 1;
            overflow-y: auto;
            padding: 16px 20px;
        }

        .step-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }

        .step-card:hover {
            transform: translateX(4px);
            border-color: #4b5563;
            background: var(--bg-card-hover);
        }

        .step-card.active {
            border-color: var(--phase-red);
            box-shadow: 0 0 16px var(--phase-red-glow);
            background: rgba(31, 41, 55, 0.9);
        }

        .step-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px;
        }

        .step-badge {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 12px;
            color: #ffffff;
        }

        .bg-red { background: var(--phase-red); box-shadow: 0 0 8px var(--phase-red-glow); }
        .bg-orange { background: var(--phase-orange); box-shadow: 0 0 8px var(--phase-orange-glow); }
        .bg-green { background: var(--phase-green); box-shadow: 0 0 8px var(--phase-green-glow); }
        .bg-purple { background: var(--phase-purple); box-shadow: 0 0 8px var(--phase-purple-glow); }
        .bg-pink { background: var(--phase-pink); box-shadow: 0 0 8px var(--phase-pink-glow); }

        .step-title {
            font-size: 13.5px;
            font-weight: 600;
            color: #ffffff;
            flex: 1;
            margin-left: 10px;
        }

        .step-phase-tag {
            font-size: 10.5px;
            font-weight: 600;
            text-transform: uppercase;
            padding: 2px 8px;
            border-radius: 4px;
            letter-spacing: 0.3px;
        }

        .step-desc {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
            line-height: 1.4;
        }

        /* Main Canvas Viewport */
        .viewport {
            flex: 1;
            position: relative;
            background: #070a11;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .canvas-toolbar {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 50;
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(17, 24, 39, 0.85);
            backdrop-filter: blur(10px);
            border: 1px solid var(--border-color);
            padding: 8px 12px;
            border-radius: 12px;
        }

        .tool-btn {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .tool-btn:hover {
            background: var(--bg-card-hover);
            border-color: #6b7280;
        }

        .simulation-btn {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #ffffff;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 4px 12px var(--phase-green-glow);
        }

        .simulation-btn:hover {
            background: linear-gradient(135deg, #34d399, #10b981);
        }

        .diagram-container {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: auto;
            position: relative;
            padding: 40px;
            cursor: grab;
        }

        .diagram-wrapper {
            position: relative;
            transition: transform 0.2s ease-out;
            transform-origin: center center;
            display: inline-block;
        }

        .diagram-img {
            max-width: 1200px;
            width: 100%;
            height: auto;
            border-radius: 16px;
            display: block;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Hotspot Pins overlaid on Isometric Image */
        .hotspot-pin {
            position: absolute;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 13px;
            color: #ffffff;
            cursor: pointer;
            transform: translate(-50%, -50%);
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.8);
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            z-index: 10;
        }

        .hotspot-pin::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            border: 2px solid currentColor;
            animation: pulse-ring 2s infinite;
        }

        @keyframes pulse-ring {
            0% { transform: scale(1); opacity: 1; }
            100% { transform: scale(1.8); opacity: 0; }
        }

        .hotspot-pin:hover, .hotspot-pin.active {
            transform: translate(-50%, -50%) scale(1.3);
            z-index: 30;
        }

        /* Detail Modal / Drawer */
        .detail-drawer {
            position: absolute;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(120%);
            width: 90%;
            max-width: 780px;
            background: rgba(17, 24, 39, 0.95);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.8);
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            z-index: 60;
        }

        .detail-drawer.open {
            transform: translateX(-50%) translateY(0);
        }

        .drawer-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .drawer-title-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .drawer-badge {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 15px;
            color: #ffffff;
        }

        .drawer-title {
            font-family: 'Outfit', sans-serif;
            font-size: 20px;
            font-weight: 700;
            color: #ffffff;
        }

        .drawer-close {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 16px;
        }

        .drawer-close:hover {
            color: #ffffff;
            background: var(--bg-card-hover);
        }

        .drawer-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }

        .drawer-desc {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.6;
        }

        .drawer-meta {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 14px;
            font-size: 12.5px;
        }

        .meta-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .meta-row:last-child { margin-bottom: 0; }
        .meta-label { color: var(--text-muted); }
        .meta-val { font-weight: 600; color: #ffffff; }

        /* Asset Gallery Bar */
        .assets-bar {
            background: var(--bg-surface);
            border-top: 1px solid var(--border-color);
            padding: 14px 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            overflow-x: auto;
        }

        .asset-item {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            padding: 6px 14px;
            border-radius: 10px;
            font-size: 12px;
            color: var(--text-main);
            white-space: nowrap;
        }

        .asset-thumb {
            width: 28px;
            height: 28px;
            object-fit: contain;
        }
    </style>
</head>
<body>
    <!-- Navigation Header -->
    <header>
        <div class="brand-container">
            <div class="brand-logo">f</div>
            <div class="brand-title">
                <h1>FieldBi Supply Chain & Logistics Workflow</h1>
                <p>Interactive End-to-End Order & Inventory Fulfillment Architecture</p>
            </div>
        </div>

        <div class="nav-links">
            <a href="api.php" class="nav-btn">📊 Server Dashboard</a>
            <a href="workflow.php" class="nav-btn active">🔄 Supply Chain Workflow</a>
            <a href="asset/workflow_diagram.png" target="_blank" class="nav-btn">🖼️ High-Res Map</a>
        </div>
    </header>

    <!-- Main Workspace -->
    <div class="workspace">
        <!-- Sidebar Timeline -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-title">
                    <span>Workflow Steps (17)</span>
                    <span style="font-size: 12px; color: var(--text-muted);">FieldBi Architecture</span>
                </div>

                <div class="phase-tabs">
                    <button class="tab-chip tab-all active" onclick="filterPhase('all')">All</button>
                    <button class="tab-chip tab-order" onclick="filterPhase('order')">🔴 Order</button>
                    <button class="tab-chip tab-warehouse" onclick="filterPhase('warehouse')">🟠 Delivery</button>
                    <button class="tab-chip tab-settlement" onclick="filterPhase('settlement')">🟢 Finance</button>
                    <button class="tab-chip tab-receiving" onclick="filterPhase('receiving')">🟣 Inbound</button>
                </div>
            </div>

            <div class="steps-container" id="stepsList">
                <!-- Dynamically Rendered Steps -->
            </div>
        </div>

        <!-- Viewport Canvas -->
        <div class="viewport">
            <div class="canvas-toolbar">
                <button class="simulation-btn" onclick="startSimulation()">
                    <span>▶️ Play Flow Animation</span>
                </button>
                <button class="tool-btn" onclick="zoomIn()" title="Zoom In">➕</button>
                <button class="tool-btn" onclick="zoomOut()" title="Zoom Out">➖</button>
                <button class="tool-btn" onclick="resetZoom()" title="Reset View">🔄</button>
            </div>

            <div class="diagram-container" id="diagramContainer">
                <div class="diagram-wrapper" id="diagramWrapper">
                    <img src="<?= $workflowImg ?>" alt="FieldBi Supply Chain Workflow Diagram" class="diagram-img" id="diagramImg">
                    
                    <!-- Overlay Pins Positioned on Diagram -->
                    <div class="hotspot-pin bg-red" style="top: 31%; left: 81%;" onclick="selectStep('1')">1</div>
                    <div class="hotspot-pin bg-red" style="top: 42%; left: 83%;" onclick="selectStep('2')">2</div>
                    <div class="hotspot-pin bg-red" style="top: 50%; left: 79%;" onclick="selectStep('3')">3</div>
                    <div class="hotspot-pin bg-red" style="top: 55%; left: 71%;" onclick="selectStep('4')">4</div>
                    <div class="hotspot-pin bg-red" style="top: 58%; left: 66%;" onclick="selectStep('5')">5</div>
                    <div class="hotspot-pin bg-red" style="top: 61%; left: 60%;" onclick="selectStep('6')">6</div>
                    <div class="hotspot-pin bg-red" style="top: 66%; left: 55%;" onclick="selectStep('7')">7</div>
                    <div class="hotspot-pin bg-red" style="top: 71%; left: 51%;" onclick="selectStep('8')">8</div>

                    <div class="hotspot-pin bg-orange" style="top: 48%; left: 28%;" onclick="selectStep('9')">9</div>
                    <div class="hotspot-pin bg-orange" style="top: 32%; left: 52%;" onclick="selectStep('10')">10</div>
                    <div class="hotspot-pin bg-green" style="top: 19%; left: 63%;" onclick="selectStep('11')">11</div>
                    <div class="hotspot-pin bg-green" style="top: 30%; left: 28%;" onclick="selectStep('12')">12</div>

                    <div class="hotspot-pin bg-purple" style="top: 63%; left: 18%;" onclick="selectStep('A')">A</div>
                    <div class="hotspot-pin bg-purple" style="top: 66%; left: 25%;" onclick="selectStep('B')">B</div>
                    <div class="hotspot-pin bg-purple" style="top: 74%; left: 36%;" onclick="selectStep('C')">C</div>
                    <div class="hotspot-pin bg-purple" style="top: 77%; left: 45%;" onclick="selectStep('D')">D</div>
                    <div class="hotspot-pin bg-pink" style="top: 14%; left: 70%;" onclick="selectStep('M')">M</div>
                </div>
            </div>

            <!-- Slide-over Detail Drawer -->
            <div class="detail-drawer" id="detailDrawer">
                <div class="drawer-header">
                    <div class="drawer-title-group">
                        <div class="drawer-badge bg-red" id="drawerBadge">1</div>
                        <div class="drawer-title" id="drawerTitle">Register Sales Order</div>
                    </div>
                    <button class="drawer-close" onclick="closeDrawer()">✕</button>
                </div>

                <div class="drawer-grid">
                    <div class="drawer-desc" id="drawerDesc">
                        Order registered by consumer, retail store, gas station, or food outlet via FieldBi platform.
                    </div>
                    <div class="drawer-meta">
                        <div class="meta-row">
                            <span class="meta-label">Process Phase:</span>
                            <span class="meta-val" id="drawerPhase">Order Processing</span>
                        </div>
                        <div class="meta-row">
                            <span class="meta-label">Responsible Role:</span>
                            <span class="meta-val" id="drawerRole">Sales Admin / Rep</span>
                        </div>
                        <div class="meta-row">
                            <span class="meta-label">System Module:</span>
                            <span class="meta-val" id="drawerModule">FieldBi Sales OMS</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PNG Asset Gallery -->
            <div class="assets-bar">
                <span style="font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Asset PNGs:</span>
                <div class="asset-item">
                    <img src="asset/9.png" class="asset-thumb" alt="Load Inventory">
                    <span>9.png (Load Inventory)</span>
                </div>
                <div class="asset-item">
                    <img src="asset/10.png" class="asset-thumb" alt="Deliver Goods">
                    <span>10.png (Deliver Goods)</span>
                </div>
                <div class="asset-item">
                    <img src="asset/11a.png" class="asset-thumb" alt="Collect Payment">
                    <span>11a.png (Collect Payment)</span>
                </div>
                <div class="asset-item">
                    <img src="asset/11b.png" class="asset-thumb" alt="Submit Payment">
                    <span>11b.png (Submit Payment)</span>
                </div>
                <div class="asset-item">
                    <img src="asset/merchandising.png" class="asset-thumb" alt="Merchandising">
                    <span>merchandising.png</span>
                </div>
                <div class="asset-item">
                    <img src="asset/receiving.png" class="asset-thumb" alt="Receiving">
                    <span>receiving.png</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        const workflowSteps = [
            { id: '1', title: 'Register Sales Order', phase: 'order', color: 'red', role: 'Sales Rep / Consumer', desc: 'Consumers, Gas Stations, Retail Stores, or Burger outlets register sales orders directly into the FieldBi platform.', module: 'FieldBi Mobile & Web OMS' },
            { id: '2', title: 'Approve by Sup, Regional, National', phase: 'order', color: 'red', role: 'Sales Manager / Regional Sup', desc: 'Multi-level approval process evaluated by Supervisor, Regional Manager, and National Sales Director.', module: 'FieldBi Approval Engine' },
            { id: '3', title: 'Sales Order Confirmed by Sales Admin', phase: 'order', color: 'red', role: 'Sales Admin', desc: 'Sales order is formally confirmed by Sales Admin after checking customer credit approval limits.', module: 'FieldBi Credit & Order Admin' },
            { id: '4', title: 'Assign Delivery', phase: 'order', color: 'red', role: 'Logistics Dispatcher', desc: 'Dispatcher assigns order to designated delivery truck route and driver fleet.', module: 'FieldBi Transport WMS' },
            { id: '5', title: 'Sum up quantity for picking', phase: 'order', color: 'red', role: 'Warehouse Manager', desc: 'System aggregates item quantities from confirmed orders to generate picking wave lists.', module: 'FieldBi Warehouse ERP' },
            { id: '6', title: 'Picking', phase: 'order', color: 'red', role: 'Forklift Operator / Picker', desc: 'Warehouse team picks items from inventory shelves using forklifts and handheld scanners.', module: 'FieldBi WMS Mobile' },
            { id: '7', title: 'Inspecting', phase: 'order', color: 'red', role: 'Quality Control Inspector', desc: 'Picked inventory items undergo quality inspection and quantity verification.', module: 'FieldBi Quality Module' },
            { id: '8', title: 'Loose Area (Staging & Invoicing)', phase: 'order', color: 'red', role: 'Dispatch Clerk', desc: 'Staging in loose area. Generates Credit Notes (8A), Pre-Invoices (8B), and Delivery Notes (8C).', module: 'FieldBi Invoicing & Staging' },
            { id: '9', title: 'Load Inventory', phase: 'warehouse', color: 'orange', role: 'Loading Crew / Driver', desc: 'Verified goods are loaded onto delivery trucks according to route sequence.', module: 'FieldBi Fleet Manager' },
            { id: '10', title: 'Deliver the goods', phase: 'warehouse', color: 'orange', role: 'Delivery Driver', desc: 'Truck transports goods to retail stores, gas station outlets, and food venues.', module: 'FieldBi Driver Mobile App' },
            { id: '11', title: 'Collect Payment', phase: 'settlement', color: 'green', role: 'Cashier / Delivery Agent', desc: 'Collect payment via Cash, KHQR, or POS upon delivery at retail outlet.', module: 'FieldBi Payment Gateway' },
            { id: '12', title: 'Clearance & Settlement', phase: 'settlement', color: 'green', role: 'Finance Account Executive', desc: 'Reconcile collected payments (Submit 11A, Receive 11B) and return unsold inventory balance (11C).', module: 'FieldBi Finance & ERP' },
            { id: 'A', title: 'Supplier Deliver the goods', phase: 'receiving', color: 'purple', role: 'Vendor / Inbound Supplier', desc: 'Supplier delivers raw materials or finished products to central distribution center.', module: 'FieldBi Vendor Portal' },
            { id: 'B', title: 'Receiving', phase: 'receiving', color: 'purple', role: 'Receiving Dock Clerk', desc: 'Dock team scans purchase order documents and inspects inbound shipment pallets.', module: 'FieldBi Inbound Receiving' },
            { id: 'C', title: 'Inbound Inspection', phase: 'receiving', color: 'purple', role: 'QA Inspector', desc: 'Comprehensive batch QA check and temperature control check for received goods.', module: 'FieldBi Inbound QA' },
            { id: 'D', title: 'Put-Away', phase: 'receiving', color: 'purple', role: 'Forklift Operator', desc: 'System directs forklift operator to optimal bin shelf locations for storage.', module: 'FieldBi Bin Location WMS' },
            { id: 'M', title: 'Merchandising & Store Execution', phase: 'merchandising', color: 'pink', role: 'Merchandiser', desc: 'Audit store shelf display performance, planograms, and consumer retail activity.', module: 'FieldBi Merchandising App' }
        ];

        let currentScale = 1;
        let activeStepId = '1';

        function renderSidebar(phase = 'all') {
            const container = document.getElementById('stepsList');
            container.innerHTML = '';

            const filtered = phase === 'all' ? workflowSteps : workflowSteps.filter(s => s.phase === phase);

            filtered.forEach(s => {
                const card = document.createElement('div');
                card.className = `step-card ${s.id === activeStepId ? 'active' : ''}`;
                card.onclick = () => selectStep(s.id);

                card.innerHTML = `
                    <div class="step-card-header">
                        <div style="display: flex; align-items: center;">
                            <div class="step-badge bg-${s.color}">${s.id}</div>
                            <div class="step-title">${s.title}</div>
                        </div>
                    </div>
                    <div class="step-desc">${s.desc}</div>
                `;
                container.appendChild(card);
            });
        }

        function filterPhase(phase) {
            document.querySelectorAll('.tab-chip').forEach(t => t.classList.remove('active'));
            event.target.classList.add('active');
            renderSidebar(phase);
        }

        function selectStep(id) {
            activeStepId = id;
            const step = workflowSteps.find(s => s.id === id);
            if (!step) return;

            // Highlight Pins & Sidebar
            document.querySelectorAll('.hotspot-pin').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.step-card').forEach(c => c.classList.remove('active'));

            renderSidebar();

            // Open Detail Drawer
            const drawer = document.getElementById('detailDrawer');
            document.getElementById('drawerBadge').className = `drawer-badge bg-${step.color}`;
            document.getElementById('drawerBadge').innerText = step.id;
            document.getElementById('drawerTitle').innerText = step.title;
            document.getElementById('drawerDesc').innerText = step.desc;
            document.getElementById('drawerPhase').innerText = step.phase.toUpperCase();
            document.getElementById('drawerRole').innerText = step.role;
            document.getElementById('drawerModule').innerText = step.module;

            drawer.classList.add('open');
        }

        function closeDrawer() {
            document.getElementById('detailDrawer').classList.remove('open');
        }

        function zoomIn() {
            currentScale += 0.15;
            applyZoom();
        }

        function zoomOut() {
            if (currentScale > 0.6) {
                currentScale -= 0.15;
                applyZoom();
            }
        }

        function resetZoom() {
            currentScale = 1;
            applyZoom();
        }

        function applyZoom() {
            document.getElementById('diagramWrapper').style.transform = `scale(${currentScale})`;
        }

        let simInterval = null;
        function startSimulation() {
            if (simInterval) clearInterval(simInterval);
            let idx = 0;
            simInterval = setInterval(() => {
                if (idx >= workflowSteps.length) {
                    clearInterval(simInterval);
                    return;
                }
                selectStep(workflowSteps[idx].id);
                idx++;
            }, 1800);
        }

        // Initialize
        renderSidebar();
    </script>
</body>
</html>
