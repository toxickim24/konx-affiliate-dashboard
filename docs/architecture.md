# Architecture

## Overview

KonX Affiliate Dashboard is a WordPress/WooCommerce plugin that provides affiliate management capabilities.

## Plugin Structure

- **Bootstrap** (`konx-affiliate-dashboard.php`) — Entry point. Defines constants, checks dependencies, and initializes the plugin.
- **Includes** (`includes/`) — Core classes and business logic.
- **Admin** (`admin/views/`) — Admin-facing UI templates.
- **Public** (`public/views/`) — Front-end UI templates.
- **Templates** (`templates/`) — Theme-overridable templates.
- **Assets** (`assets/`) — CSS and JavaScript files.
- **Languages** (`languages/`) — Translation files.

## Dependencies

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+

## Design Decisions

Architecture decisions will be documented here as the plugin evolves.
