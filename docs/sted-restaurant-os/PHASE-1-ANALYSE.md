# STED RESTAURANT OS — PHASE 1 : ANALYSE ARCHITECTURALE COMPLÈTE

> Document d'architecture — **aucun code n'est produit à cette phase**.
> Livrable soumis à validation avant la Phase 2 (Database & Domain).
>
> Version 1.0 · Statut : *En attente de validation*

---

## Table des matières

1. [Vision globale du système](#1-vision-globale-du-système)
2. [Architecture générale](#2-architecture-générale)
3. [Bounded Contexts](#3-bounded-contexts)
4. [Modules](#4-modules)
5. [Agrégats DDD](#5-agrégats-ddd)
6. [Entités principales](#6-entités-principales)
7. [Value Objects](#7-value-objects)
8. [Relations métier](#8-relations-métier)
9. [Règles métier](#9-règles-métier)
10. [Workflow complet d'une commande](#10-workflow-complet-dune-commande)
11. [Workflow serveur / table](#11-workflow-serveur--table)
12. [Workflow transfert de table](#12-workflow-transfert-de-table)
13. [Workflow cuisine](#13-workflow-cuisine)
14. [Workflow bar](#14-workflow-bar)
15. [Workflow caisse](#15-workflow-caisse)
16. [Modèle multi-tenant](#16-modèle-multi-tenant)
17. [Modèle de sécurité](#17-modèle-de-sécurité)
18. [Architecture SignalR](#18-architecture-signalr)
19. [Architecture API](#19-architecture-api)
20. [Architecture frontend](#20-architecture-frontend)
21. [Proposition MCD](#21-proposition-mcd)
22. [Proposition MLD](#22-proposition-mld)
23. [Principaux endpoints REST](#23-principaux-endpoints-rest)
24. [Stratégie de concurrence](#24-stratégie-de-concurrence)
25. [Stratégie d'idempotence](#25-stratégie-didempotence)
26. [Stratégie d'audit](#26-stratégie-daudit)
27. [Stratégie de tests](#27-stratégie-de-tests)
28. [Stratégie Docker / DevOps](#28-stratégie-docker--devops)
29. [Risques techniques](#29-risques-techniques)
30. [Recommandations d'architecture](#30-recommandations-darchitecture)
31. [Arborescence complète du projet](#31-arborescence-complète-du-projet)
32. [Roadmap de développement](#32-roadmap-de-développement)

---

## 1. Vision globale du système

### 1.1 Problème métier

Dans un établissement à fort volume (restaurant, bar, lounge, hôtel-restaurant), le
**serveur est le goulot d'étranglement** : le client attend qu'un serveur soit
disponible pour commander, alors que la cuisine et le bar sont souvent libres.
Résultat : temps d'attente perçu élevé, rotation des tables ralentie, ticket moyen
réduit, et aucune donnée fiable sur la performance réelle du service.

### 1.2 Proposition de valeur

STED Restaurant OS décorrèle **la prise de commande** de **la disponibilité du serveur** :

- le client commande depuis son téléphone via le QR Code de sa table ;
- la commande atterrit **immédiatement** en cuisine et au bar, éclatée par station ;
- le serveur reste **responsable du service** (accueil, service, encaissement) et
  garde la traçabilité complète de ses tables ;
- l'établissement obtient une **piste d'audit exhaustive** : qui a fait quoi, quand,
  sur quelle table, pour quelle session.

### 1.3 Le principe fondateur (à ne jamais violer)

```
LE QR CODE IDENTIFIE UNE TABLE.             →  objet physique, public, non secret
LE COMPTE AUTHENTIFIÉ IDENTIFIE LE SERVEUR. →  identité, privée, prouvée

Le serveur d'une commande n'est JAMAIS déduit du QR Code.
Il est déduit de la ServiceAssignment ACTIVE de la TableSession en cours.
```

Cette séparation est ce qui rend le système auditable. Toute l'architecture du
domaine en découle.

### 1.4 Le second principe : la session, pas la table

Une table est un **meuble** — elle vit des années. Un service est un **épisode** —
il dure 45 minutes. Rattacher les commandes à la table serait une erreur de
modélisation irréparable : on ne pourrait plus répondre à « combien de couverts la
table 12 a-t-elle fait hier soir ? ».

```
RestaurantTable   (le meuble, permanent)
      └── TableSession  (l'épisode de service, éphémère)
              ├── ServiceAssignment  (qui sert, dans le temps)
              └── Order              (ce qui est consommé)
```

### 1.5 Périmètre MVP

| Inclus dans le MVP | Exclu du MVP (architecture préparée) |
|---|---|
| Commande client par QR | Paiements mobiles (MonCash, NatCash, Stripe) |
| KDS cuisine + bar temps réel | Livraison / Takeaway |
| Gestion serveurs, tables, sessions, transferts | Réservations |
| Caisse + paiement CASH | Fidélité |
| Rapports & analytics serveurs/tables/produits | Stocks, achats, fournisseurs |
| Audit complet | Comptabilité, multi-sites / franchise |
| Multi-tenant SaaS | Application mobile native |

### 1.6 Personas et écrans

| Persona | Écran | Device | Contrainte dominante |
|---|---|---|---|
| Client final | Menu / Panier / Suivi | Smartphone | Une seule main, réseau instable, zéro formation |
| Serveur | Mes tables | Smartphone / tablette | Rapidité, gros boutons, debout en mouvement |
| Cuisine | KDS | Écran 24"+ / tablette | Lisibilité à 2 m, mains occupées |
| Bar | BDS | Tablette | Idem cuisine, volume élevé, tickets courts |
| Caissier | Caisse | Desktop / tablette | Exactitude, rapidité d'encaissement |
| Manager / Admin | Dashboard, rapports | Desktop | Densité d'information, filtres, export |
| Super Admin | Console SaaS | Desktop | Provisioning tenants, supervision |

---

## 2. Architecture générale

### 2.1 Style retenu : Modular Monolith + Clean Architecture

**Décision ADR-001 — Monolithe modulaire, pas de microservices.**

*Contexte* : équipe réduite, MVP à livrer, forte cohésion transactionnelle
(commande + items + tickets + historique doivent être atomiques).
*Décision* : un seul déployable API, découpé en modules verticaux à frontières
explicites, avec Clean Architecture en coupe transversale.
*Conséquences* : transactions ACID simples, déploiement trivial, latence minimale ;
en contrepartie, discipline requise pour ne pas laisser les modules se coupler
(règle : un module ne référence un autre que par ses interfaces `Application/Abstractions`).
*Chemin d'extraction* : chaque module possède déjà son schéma logique, ses events et
ses contrats ; l'extraction future d'un module (ex. Reporting, Payments) se fait en
remplaçant l'appel in-process par un appel HTTP/message, sans réécrire le domaine.

### 2.2 Vue en couches

```
┌───────────────────────────────────────────────────────────────────────┐
│  STED.RestaurantOS.API                                                │
│  Controllers · Hubs SignalR · Middlewares · Filters · Swagger         │
│  Auth handlers · Rate limiting · Global exception handler             │
└───────────────────────────┬───────────────────────────────────────────┘
                            │ dépend de
┌───────────────────────────▼───────────────────────────────────────────┐
│  STED.RestaurantOS.Application                                        │
│  Use cases (Commands/Queries) · DTOs · Validators FluentValidation    │
│  Abstractions (IOrderRepository, IUnitOfWork, IRealtimeNotifier,      │
│  ITenantContext, IPaymentProvider, IDateTimeProvider, IQrTokenFactory)│
│  Pipeline behaviors : Validation · Logging · Transaction · Audit      │
└───────────┬───────────────────────────────────────┬───────────────────┘
            │ dépend de                             │
┌───────────▼───────────────────────┐   ┌───────────▼───────────────────┐
│  STED.RestaurantOS.Domain         │   │  STED.RestaurantOS.Shared     │
│  Aggregates · Entities · VOs      │   │  Result<T> · ApiResponse      │
│  Enums · Domain Events            │   │  PagedResult · ErrorCodes     │
│  Domain Services · Exceptions     │   │  Constantes · Guard clauses   │
│  ZÉRO dépendance externe          │   │  Aucune dépendance métier     │
└───────────────────────────────────┘   └───────────────────────────────┘
            ▲
            │ implémente les abstractions
┌───────────┴───────────────────────────────────────────────────────────┐
│  STED.RestaurantOS.Infrastructure                                     │
│  EF Core DbContext · Configurations · Migrations · Repositories       │
│  Identity · JWT · Refresh tokens · SignalR notifier                   │
│  Audit interceptor · Idempotency store · Payment providers · Serilog  │
└───────────────────────────────────────────────────────────────────────┘
```

**Règle de dépendance** : les flèches pointent toujours vers l'intérieur.
`Domain` ne connaît ni EF Core, ni ASP.NET, ni SignalR.
`Application` ne connaît pas EF Core (uniquement `IRepository` / `IUnitOfWork`).
`Infrastructure` et `API` sont interchangeables sans toucher au métier.

### 2.3 Vue de déploiement

```
                       Internet (HTTPS 443)
                              │
                    ┌─────────▼─────────┐
                    │      Nginx        │  TLS, gzip/brotli, static,
                    │   reverse proxy   │  WebSocket upgrade, /api → API
                    └────┬─────────┬────┘
             /  (SPA)    │         │  /api  /hubs
          ┌──────────────▼──┐   ┌──▼────────────────────────┐
          │  Frontend PWA   │   │  ASP.NET Core 10 Web API  │
          │  React + Vite   │   │  + SignalR Hub            │
          │  (build static) │   │  health: /health/live     │
          └─────────────────┘   │         /health/ready     │
                                └────┬──────────────┬───────┘
                                     │              │
                        ┌────────────▼───┐   ┌──────▼─────────┐
                        │ SQL Server 2022│   │  Seq / fichier │
                        │ système de     │   │  logs Serilog  │
                        │ référence      │   │  structurés    │
                        └────────────────┘   └────────────────┘
```

*Note scalabilité* : le passage à plusieurs instances API impose un backplane SignalR
(Redis). L'interface `IRealtimeNotifier` isole déjà ce choix — voir §18.5.

### 2.4 CQRS pragmatique

**Décision ADR-002 — CQRS léger, sans event sourcing, sans base de lecture séparée.**

- **Commands** : passent par les agrégats du domaine, transactionnelles, valident les
  invariants métier, émettent des domain events.
- **Queries** : projections LINQ directes `AsNoTracking()` vers des DTOs, sans passer
  par les agrégats (les dashboards et rapports n'ont pas besoin d'objets riches).

C'est le seul découplage qui rapporte réellement ici : le KDS et les rapports ont des
besoins de lecture radicalement différents du modèle d'écriture.

### 2.5 Domain events

Deux natures d'événements, à ne pas confondre :

| | Domain Event (interne) | Integration Event (temps réel) |
|---|---|---|
| Émis par | l'agrégat, en mémoire | le dispatcher, après commit |
| Consommé par | handlers in-process (historique, audit, tickets) | SignalR, futurs consommateurs externes |
| Transaction | **dans** la transaction | **après** le commit |
| Exemple | `OrderConfirmedDomainEvent` | `order.confirmed` sur `restaurant:{id}` |

**Règle absolue** : aucun événement temps réel n'est publié avant le commit réussi de
la transaction (§55 du cahier des charges). Le dispatch se fait dans un
`TransactionBehavior`, après `CommitAsync()`.

---

## 3. Bounded Contexts

Neuf contextes, chacun propriétaire exclusif de ses tables SQL.

```
┌──────────────────────┐   ┌──────────────────────┐   ┌──────────────────────┐
│  IDENTITY & ACCESS   │   │  RESTAURANT CONFIG   │   │  FLOOR MANAGEMENT    │
│  Users, Roles,       │──▶│  Restaurant,         │──▶│  Zones, Tables,      │
│  Permissions, JWT,   │   │  Settings, Staff     │   │  QR Codes, Sessions, │
│  Refresh tokens      │   │  profiles            │   │  ServiceAssignments  │
└──────────────────────┘   └──────────┬───────────┘   └──────────┬───────────┘
                                      │                          │
                           ┌──────────▼───────────┐              │
                           │  CATALOG             │              │
                           │  Categories, Products│              │
                           │  Modifiers, Stations │              │
                           └──────────┬───────────┘              │
                                      │                          │
                           ┌──────────▼──────────────────────────▼──────────┐
                           │  ORDERING  (contexte central)                  │
                           │  Cart, Order, OrderItem, OrderStatusHistory    │
                           └──────────┬──────────────────────┬──────────────┘
                                      │                      │
                     ┌────────────────▼──────┐   ┌───────────▼─────────────┐
                     │  PREPARATION          │   │  BILLING                │
                     │  Tickets, KDS, BDS    │   │  Payments, Clôture      │
                     └───────────────────────┘   └─────────────────────────┘

        ┌──────────────────────┐   ┌──────────────────────┐
        │  REPORTING/ANALYTICS │   │  AUDIT & NOTIFICATION│   (contextes
        │  lecture seule       │   │  transverses         │    support)
        └──────────────────────┘   └──────────────────────┘
```

### 3.1 Détail des contextes

| # | Contexte | Responsabilité | Langage ubiquitaire |
|---|---|---|---|
| 1 | **Identity & Access** | Authentifier, autoriser, gérer les jetons | User, Role, Permission, Claim, RefreshToken |
| 2 | **Restaurant Config** | Le tenant et ses paramètres, le personnel | Restaurant, Settings, StaffProfile, EmployeeCode |
| 3 | **Floor Management** | Le plan de salle et la vie du service | Zone, Table, QrCode, Session, Assignment, Transfert |
| 4 | **Catalog** | Ce qui est vendable et où c'est préparé | Category, Product, Modifier, Option, Station |
| 5 | **Ordering** | Le cycle de vie d'une commande | Cart, Order, Item, Statut, Total |
| 6 | **Preparation** | L'exécution en cuisine et au bar | Ticket, Ligne, Accepté, Prêt, Retard |
| 7 | **Billing** | Encaisser et clôturer | Payment, Méthode, Réduction, Reçu |
| 8 | **Reporting** | Répondre aux questions du gérant | Rapport, KPI, Période, Performance |
| 9 | **Audit & Notification** | Prouver et informer | AuditLog, CorrelationId, Notification |

### 3.2 Relations inter-contextes (Context Map)

| Amont | Aval | Type de relation |
|---|---|---|
| Identity & Access | Tous | *Shared Kernel* — `UserId`, `RestaurantId`, permissions |
| Restaurant Config | Tous | *Conformist* — `RestaurantId` est la clé de tenancy partout |
| Catalog | Ordering | *Customer/Supplier* — Ordering lit prix/station et **snapshote** |
| Floor Management | Ordering | *Customer/Supplier* — Ordering demande « qui sert cette session ? » |
| Ordering | Preparation | *Published Language* — `OrderConfirmed` déclenche les tickets |
| Ordering | Billing | *Customer/Supplier* — Billing lit le total, écrit le statut CLOSED |
| Tous | Audit | *Observer* — via events et interceptor, jamais l'inverse |

**Point d'attention** : la relation Catalog → Ordering est la seule où une donnée
traverse par **copie et non par référence** (snapshot du prix et du nom). C'est
volontaire et non négociable (RG-040).

---

## 4. Modules

Découpage physique en dossiers verticaux à l'intérieur de chaque couche.

```
Application/Modules/
 ├── Authentication/        Login, Logout, Refresh, Reset password
 ├── Restaurants/           CRUD restaurant, settings
 ├── Staff/                 Profils employés, activation, rôles
 ├── Tables/                Zones, tables, statuts
 ├── QrCodes/               Génération, rotation, résolution publique
 ├── TableSessions/         Ouverture, fermeture, couverts
 ├── ServiceAssignments/    Prise, libération, transfert, historique
 ├── Menu/                  Catégories, produits, modifiers, disponibilité
 ├── Stations/              Kitchen, Bar, Counter
 ├── Orders/                Panier, création, confirmation, statuts, annulation
 ├── Preparation/           Tickets, KDS, BDS
 ├── Waiter/                Dashboard serveur (queries dédiées)
 ├── Cashier/               Dashboard caisse, réductions, clôture
 ├── Payments/              Paiement, providers, reçus
 ├── Notifications/         Diffusion, préférences
 ├── Reports/               Rapports agrégés
 ├── Analytics/             KPI serveurs / tables / produits / stations
 ├── AuditLogs/             Consultation de la piste d'audit
 └── Settings/              Paramétrage restaurant
```

Chaque module contient : `Commands/`, `Queries/`, `Dtos/`, `Validators/`,
`EventHandlers/`, `Abstractions/`.

---

## 5. Agrégats DDD

### 5.1 Identification des agrégats

Le critère de découpage est **la frontière transactionnelle et de cohérence** :
ce qui doit être vrai *immédiatement* est dans le même agrégat ; ce qui peut être vrai
*à terme* est dans un autre agrégat lié par identifiant.

| Agrégat (racine) | Membres internes | Invariants protégés |
|---|---|---|
| **Restaurant** | RestaurantSettings | Slug unique, devise non modifiable après 1ʳᵉ vente |
| **StaffProfile** | — | EmployeeCode unique par restaurant, 1 profil par User/Restaurant |
| **TableZone** | — | Nom unique par restaurant |
| **RestaurantTable** | TableQrCode (0..n, 1 seul actif) | Numéro unique par restaurant ; 1 seul QR actif |
| **TableSession** ★ | ServiceAssignment (0..n), ServiceAssignmentHistory | **Une seule assignment ACTIVE** ; pas de fermeture si commandes non réglées |
| **MenuCategory** | — | Nom unique par restaurant, ordre d'affichage |
| **Product** | ProductModifier, ModifierOption | Prix ≥ 0, station obligatoire, min ≤ max sur modifiers |
| **Order** ★ | OrderItem, OrderStatusHistory | Totaux = somme des lignes ; transitions légales ; immuable après CLOSED |
| **PreparationTicket** | PreparationTicketItem | 1 ticket = 1 station ; statut dérivé des lignes |
| **Payment** | — | Somme des paiements ≤ total commande ; idempotence |
| **AuditLog** | — | Append-only, jamais modifié ni supprimé |

★ = agrégats critiques, au cœur de la traçabilité.

### 5.2 Pourquoi `ServiceAssignment` appartient à `TableSession`

C'est **la décision de modélisation la plus importante du système**.

L'invariant « *une session n'a qu'un seul serveur actif à un instant t* » doit être
garanti de façon immédiate et transactionnelle. Si `ServiceAssignment` était une racine
d'agrégat indépendante, deux requêtes concurrentes pourraient créer deux assignments
actives sur la même session sans qu'aucun agrégat ne s'en aperçoive.

En plaçant les assignments **dans** `TableSession` :

```
TableSession (racine)
 ├── Assignments : List<ServiceAssignment>        ← état courant
 ├── History     : List<ServiceAssignmentHistory> ← trace immuable
 ├── AssignWaiter(waiterId, assignedBy, at)
 ├── TransferTo(newWaiterId, changedBy, reason, at)
 ├── UnassignWaiter(changedBy, reason, at)
 ├── CurrentWaiterId  → l'assignment ACTIVE, ou null
 └── Close(closedBy, at)
```

…toute mutation passe par la racine, qui vérifie l'invariant, écrit l'historique et
émet l'événement. **Il devient impossible d'écrire un transfert sans écrire son
historique** — la traçabilité n'est plus une convention respectée par les développeurs,
elle est une propriété structurelle du code.

Défense en profondeur en base : index unique filtré
`UX_ServiceAssignment_ActiveBySession (TableSessionId) WHERE Status = 'ACTIVE'`.

### 5.3 Pourquoi `Order` ne contient pas les `PreparationTicket`

Un ticket a un cycle de vie propre, piloté par un autre acteur (le cuisinier), à un
autre rythme, sur un autre écran. Les mettre dans `Order` créerait une contention
énorme : chaque « Accepter » en cuisine verrouillerait la commande entière et entrerait
en conflit avec l'ajout d'un item par le client.

`PreparationTicket` est donc une racine séparée, liée par `OrderId`.
La cohérence est **éventuelle** : quand tous les tickets d'une commande sont `READY`,
un handler passe la commande en `READY` (ou `PARTIALLY_READY`).

### 5.4 Cohérence éventuelle assumée

| Chaîne | Délai | Mécanisme |
|---|---|---|
| Order CONFIRMED → tickets créés | même transaction | domain event handler in-transaction |
| Tickets tous READY → Order READY | < 1 s | domain event `TicketReadyDomainEvent` |
| Order créé → KDS affiche | < 1 s | SignalR après commit |
| Order/Payment → rapports | temps réel (requêtes directes) | pas de projection asynchrone au MVP |

---

## 6. Entités principales

### 6.1 Identity & Access

| Entité | Champs clés | Notes |
|---|---|---|
| `ApplicationUser` | Id, Email, UserName, PasswordHash, RestaurantId?, IsActive, SecurityStamp | Hérite `IdentityUser<Guid>`. `RestaurantId` nul pour SUPER_ADMIN |
| `ApplicationRole` | Id, Name, RestaurantId? | Hérite `IdentityRole<Guid>` |
| `Permission` | Id, Code, Module, Description | Table de référence, seedée |
| `RolePermission` | RoleId, PermissionId | Matrice rôle → permissions |
| `UserPermissionOverride` | UserId, PermissionId, IsGranted | Exception individuelle (grant/revoke) |
| `RefreshToken` | Id, UserId, TokenHash, ExpiresAt, RevokedAt, ReplacedByTokenId, CreatedByIp | **Hash stocké, jamais le token en clair** |

### 6.2 Restaurant Config

| Entité | Champs clés |
|---|---|
| `Restaurant` | Id, TenantId, Name, Slug, Address, Phone, Email, LogoUrl, Currency, Timezone, IsActive, CreatedAt, UpdatedAt |
| `RestaurantSettings` | RestaurantId (PK/FK 1-1), DefaultTaxRate, TaxIncludedInPrice, ServiceChargeRate, OrderRequiresWaiterConfirmation, AllowGuestOrdering, MaxOpenOrdersPerSession, KdsLateThresholdMinutes, KdsWarningThresholdMinutes, QrTokenRotationDays, MaxDiscountPercentage, ReceiptHeader, ReceiptFooter, PrinterConfigJson, OpeningHoursJson |
| `StaffProfile` | Id, UserId, RestaurantId, EmployeeCode, DisplayName, Phone, PrimaryRole, IsActive, HiredAt, CreatedAt, UpdatedAt |

### 6.3 Floor Management

| Entité | Champs clés |
|---|---|
| `TableZone` | Id, RestaurantId, Name, DisplayOrder, IsActive |
| `RestaurantTable` | Id, RestaurantId, ZoneId, Number, Name, Capacity, Status, IsActive, RowVersion, CreatedAt, UpdatedAt |
| `TableQrCode` | Id, RestaurantId, TableId, SecureTokenHash, TokenLookupKey, IsActive, ExpiresAt?, CreatedAt, RegeneratedAt, CreatedBy |
| `TableSession` | Id, RestaurantId, TableId, SessionNumber, Status, GuestCount, StartedAt, EndedAt?, OpenedBy, ClosedBy?, Notes, RowVersion |
| `ServiceAssignment` | Id, RestaurantId, TableId, TableSessionId, WaiterId, Status, AssignedAt, UnassignedAt?, AssignedBy, Notes |
| `ServiceAssignmentHistory` | Id, RestaurantId, TableId, TableSessionId, PreviousWaiterId?, NewWaiterId?, Action, ChangedBy, ChangedAt, Reason |

### 6.4 Catalog

| Entité | Champs clés |
|---|---|
| `Station` | Id, RestaurantId, Code (KITCHEN/BAR/COUNTER), Name, IsActive, DisplayOrder |
| `MenuCategory` | Id, RestaurantId, Name, Description, ImageUrl, DisplayOrder, IsActive |
| `Product` | Id, RestaurantId, CategoryId, StationId, Name, Description, ImageUrl, Price, TaxRate, PreparationMinutes, IsAvailable, IsActive, DisplayOrder, RowVersion |
| `ProductModifier` | Id, ProductId, Name, IsRequired, MinSelections, MaxSelections, DisplayOrder |
| `ModifierOption` | Id, ProductModifierId, Name, PriceDelta, IsDefault, IsAvailable, DisplayOrder |

### 6.5 Ordering

| Entité | Champs clés |
|---|---|
| `Order` | Id, RestaurantId, TableId, TableSessionId, OrderNumber, WaiterId?, Source (QR/WAITER/COUNTER), Status, Subtotal, TaxAmount, DiscountAmount, ServiceChargeAmount, Total, Currency, Notes, CreatedAt, ConfirmedAt?, StartedAt?, ReadyAt?, ServedAt?, ClosedAt?, CancelledAt?, CreatedBy, ServedBy?, ClosedBy?, CancelledBy?, CancellationReason?, RowVersion |
| `OrderItem` | Id, OrderId, ProductId, ProductNameSnapshot, UnitPriceSnapshot, TaxRateSnapshot, StationId, StationCodeSnapshot, Quantity, ModifiersTotal, LineSubtotal, LineTax, LineTotal, Notes, Status |
| `OrderItemModifier` | Id, OrderItemId, ModifierOptionId, ModifierNameSnapshot, OptionNameSnapshot, PriceDeltaSnapshot |
| `OrderStatusHistory` | Id, OrderId, PreviousStatus, NewStatus, ChangedBy, ChangedAt, Note |

### 6.6 Preparation

| Entité | Champs clés |
|---|---|
| `PreparationTicket` | Id, RestaurantId, OrderId, StationId, TicketNumber, Status, CreatedAt, AcceptedAt?, StartedAt?, ReadyAt?, AcceptedBy?, CompletedBy?, RowVersion |
| `PreparationTicketItem` | Id, PreparationTicketId, OrderItemId, ProductNameSnapshot, Quantity, Notes, ModifiersSummary, Status |

### 6.7 Billing, Audit, Notification

| Entité | Champs clés |
|---|---|
| `Payment` | Id, RestaurantId, OrderId, TableSessionId, Amount, Currency, Method, Status, TransactionReference?, ProviderPayloadJson?, PaidAt?, ProcessedBy, IdempotencyKey, CreatedAt |
| `IdempotencyRecord` | Id, RestaurantId, Key, Endpoint, RequestHash, ResponseStatusCode, ResponseBody, CreatedAt, ExpiresAt |
| `AuditLog` | Id, RestaurantId?, UserId?, Action, EntityName, EntityId, OldValues, NewValues, IpAddress, UserAgent, CorrelationId, CreatedAt |
| `Notification` | Id, RestaurantId, TargetType (USER/ROLE/STATION), TargetId, Type, Title, Body, PayloadJson, IsRead, ReadAt?, CreatedAt |

---

## 7. Value Objects

Les VO sont immuables, comparés par valeur, validés à la construction.

| Value Object | Composition | Rôle / invariant |
|---|---|---|
| `Money` | `decimal Amount`, `string Currency` | **Le VO le plus important.** Arrondi à 2 décimales, addition impossible entre devises différentes |
| `TaxRate` | `decimal Value` (0..1) | Calcul de taxe centralisé, arrondi cohérent |
| `Percentage` | `decimal Value` (0..100) | Remises, service charge |
| `QrToken` | `string Value` (base64url, 32 octets) | Cryptographiquement aléatoire, jamais séquentiel |
| `OrderNumber` | `string Value` | Format `#{yyMMdd}-{seq}` par restaurant, lisible en cuisine |
| `EmployeeCode` | `string Value` | Alphanumérique 3-10, unique par restaurant |
| `EmailAddress` | `string Value` | Normalisé en minuscules, validé |
| `PhoneNumber` | `string Value` | E.164 tolérant |
| `Slug` | `string Value` | `[a-z0-9-]{3,60}`, unique globalement |
| `GuestCount` | `int Value` | 1..99 |
| `TimeWindow` | `DateTimeOffset From`, `To` | Filtres de rapport, `From < To` |
| `PermissionCode` | `string Value` | `Module.Action`, validé contre le catalogue |

### 7.1 Sur `Money` — décision ADR-003

*Décision* : `decimal(18,2)` en base, `decimal` en C#, encapsulé dans `Money`.
**Jamais** `double` ou `float`.
*Justification* : la gourde haïtienne (HTG) affiche des montants à 5-6 chiffres ; les
erreurs d'arrondi binaires sur des additions répétées produiraient des écarts de caisse
réels. `decimal` est exact en base 10.
*Arrondi* : `MidpointRounding.AwayFromZero` à 2 décimales, appliqué **une seule fois par
ligne**, jamais sur des totaux intermédiaires (évite l'accumulation d'erreur).

### 7.2 Identifiants fortement typés

`RestaurantId`, `OrderId`, `TableId`, `WaiterId`… sont des `readonly record struct`
enveloppant un `Guid`. Cela rend **impossible à la compilation** de passer un `TableId`
là où un `TableSessionId` est attendu — une classe entière de bugs éliminée dans un
domaine où six identifiants circulent ensemble.

*Coût* : converters EF Core à écrire (~15 lignes par type, générables).
*Bénéfice* : jugé largement supérieur au coût sur ce domaine précis.

### 7.3 Choix des clés primaires — ADR-004

**`Guid` séquentiels (`NEWSEQUENTIALID()` / `Guid.CreateVersion7()`) comme PK.**

- Pas d'énumération d'IDs depuis l'extérieur.
- Génération côté application (utile pour construire un graphe d'objets avant commit).
- Séquentiels ⇒ pas de fragmentation d'index clusterisé (le défaut mortel des Guid v4).
- Les numéros **lisibles par les humains** (`OrderNumber`, `TicketNumber`,
  `SessionNumber`) sont des colonnes séparées, séquentielles par restaurant.

---

## 8. Relations métier

### 8.1 Graphe principal

```
Restaurant (1) ──── (1) RestaurantSettings
    │
    ├── (n) TableZone ──── (n) RestaurantTable
    │                            │
    │                            ├── (n) TableQrCode      [1 seul IsActive]
    │                            └── (n) TableSession
    │                                      │
    │                                      ├── (n) ServiceAssignment  [1 seule ACTIVE]
    │                                      │            └── (1) StaffProfile [WAITER]
    │                                      ├── (n) ServiceAssignmentHistory
    │                                      └── (n) Order
    │                                                │
    │                                                ├── (n) OrderItem
    │                                                │        └── (n) OrderItemModifier
    │                                                ├── (n) OrderStatusHistory
    │                                                ├── (n) PreparationTicket
    │                                                │        └── (n) PrepTicketItem
    │                                                └── (n) Payment
    │
    ├── (n) Station ──── (n) Product
    ├── (n) MenuCategory ──── (n) Product
    │                              └── (n) ProductModifier ──── (n) ModifierOption
    ├── (n) StaffProfile ──── (1) ApplicationUser
    ├── (n) AuditLog
    └── (n) Notification
```

### 8.2 La chaîne de traçabilité

C'est la colonne vertébrale du système :

```
Restaurant → Table → TableSession → ServiceAssignment → Waiter → Order
```

**Elle est doublement matérialisée**, et c'est volontaire :

1. `Order.TableSessionId` → permet de remonter la chaîne complète à tout moment.
2. `Order.WaiterId` → **dénormalisation figée** au moment de la confirmation.

*Pourquoi les deux ?* Parce que la chaîne « live » change (transfert de table) alors que
la responsabilité d'une commande passée ne doit **jamais** changer (RG-036).
`Order.WaiterId` est un fait historique gelé ; la chaîne est l'état courant.
`ServiceAssignmentHistory` est la réconciliation entre les deux dans le temps.

### 8.3 Suppressions

**Aucune suppression physique** sur les entités porteuses d'historique :
`Order`, `OrderItem`, `OrderStatusHistory`, `TableSession`, `ServiceAssignment`,
`ServiceAssignmentHistory`, `Payment`, `AuditLog`, `PreparationTicket`.

`DeleteBehavior.Restrict` partout sur ces chemins. Les entités de référence
(`Product`, `Table`, `StaffProfile`, `Category`) utilisent la **désactivation**
(`IsActive = false`), jamais le `DELETE` — sans quoi les commandes historiques
perdraient leurs jointures.

---

## 9. Règles métier

Règles numérotées, testables, référencées par les tests unitaires.

### 9.1 Tenancy & sécurité

| # | Règle |
|---|---|
| RG-001 | Toute entité métier porte un `RestaurantId` non nul (sauf `ApplicationUser` SUPER_ADMIN). |
| RG-002 | Le `RestaurantId` est **toujours** dérivé du contexte authentifié, jamais du corps ou de l'URL de la requête. |
| RG-003 | Toute requête EF Core sur une entité tenant est filtrée par le `RestaurantId` courant via un *global query filter*. |
| RG-004 | Une écriture dont le `RestaurantId` diffère du contexte est rejetée à `SaveChanges` (défense en profondeur). |
| RG-005 | Un utilisateur sans permission reçoit `403` ; une ressource d'un autre tenant renvoie `404` (non-divulgation). |

### 9.2 QR Codes & sessions client

| # | Règle |
|---|---|
| RG-010 | Un token QR est aléatoire (≥ 256 bits), stocké **hashé** (SHA-256), jamais dérivé d'un identifiant. |
| RG-011 | Une table n'a **qu'un seul** QR actif ; la régénération désactive l'ancien immédiatement. |
| RG-012 | Un token invalide, expiré ou désactivé ⇒ `404 QR_CODE_INVALID`, sans révéler si la table existe. |
| RG-013 | Résoudre un QR ouvre (ou rejoint) la `TableSession` OPEN/ACTIVE de la table et délivre un **jeton invité** court (audience `guest`), lié à `sessionId` + `tableId`. |
| RG-014 | Un jeton invité ne donne accès **qu'à** sa propre session : menu, son panier, ses commandes, son total. Rien d'autre. |
| RG-015 | Le jeton invité expire à la fermeture de la session ou après N heures (paramétrable). |

### 9.3 Tables & sessions

| # | Règle |
|---|---|
| RG-020 | Une table a **au plus une** session non close (OPEN ou ACTIVE) à la fois. |
| RG-021 | Une session close est immuable : aucune commande ne peut y être ajoutée. |
| RG-022 | Fermer une session exige que toutes ses commandes soient CLOSED ou CANCELLED. |
| RG-023 | La fermeture de la session passe la table en `CLEANING` puis `AVAILABLE`. |
| RG-024 | `GuestCount` est modifiable tant que la session est ouverte ; chaque modification est auditée. |

### 9.4 Affectation de service

| # | Règle |
|---|---|
| RG-030 | Une session a **au plus une** `ServiceAssignment` de statut ACTIVE. |
| RG-031 | Prendre une table déjà prise échoue avec `TABLE_ALREADY_ASSIGNED` (409), sauf MANAGER/ADMIN qui peuvent réassigner. |
| RG-032 | Tout changement d'affectation écrit **obligatoirement** une ligne `ServiceAssignmentHistory` dans la même transaction. |
| RG-033 | L'historique d'affectation est **append-only** : jamais modifié, jamais supprimé. |
| RG-034 | Un serveur ne peut transférer qu'une table dont il est le serveur actif ; MANAGER+ peut transférer n'importe quelle table. |
| RG-035 | Le serveur cible d'un transfert doit être actif, du même restaurant, et porter le rôle WAITER. |
| RG-036 | Un transfert n'altère **jamais** le `WaiterId` des commandes déjà créées. |

### 9.5 Commandes & prix

| # | Règle |
|---|---|
| RG-040 | Le prix, le nom, la taxe et la station d'un produit sont **snapshotés** dans `OrderItem` à la confirmation. |
| RG-041 | Une commande passée n'est **jamais** recalculée avec les prix actuels du catalogue. |
| RG-042 | Le backend recalcule intégralement `Subtotal`, `TaxAmount`, `DiscountAmount`, `Total` depuis la base. Les montants envoyés par le client sont **ignorés**. |
| RG-043 | Un produit `IsAvailable = false` ou `IsActive = false` ne peut pas être commandé (`PRODUCT_UNAVAILABLE`, 409). |
| RG-044 | Les quantités sont des entiers 1..99 par ligne. |
| RG-045 | Les modifiers respectent `MinSelections`/`MaxSelections` et `IsRequired`. |
| RG-046 | `Order.WaiterId` est renseigné à la confirmation depuis la `ServiceAssignment` ACTIVE ; il est **immuable ensuite**. |
| RG-047 | Une commande sans serveur assigné est valide (`WaiterId` null) et apparaît dans la file « tables non assignées ». |
| RG-048 | Transitions légales uniquement (§10.4) ; toute autre ⇒ `INVALID_ORDER_STATUS_TRANSITION` (409). |
| RG-049 | Chaque transition écrit une ligne `OrderStatusHistory` dans la même transaction. |
| RG-050 | Une commande CANCELLED exige une raison et la permission `Orders.Cancel`. |
| RG-051 | Une commande dont un ticket est déjà `IN_PREPARATION` ne peut être annulée que par MANAGER+. |
| RG-052 | Une commande CLOSED est immuable. |

### 9.6 Préparation

| # | Règle |
|---|---|
| RG-060 | La confirmation d'une commande crée **un ticket par station distincte** présente dans les lignes. |
| RG-061 | Un ticket ne contient que les lignes de sa station. |
| RG-062 | Le statut d'une commande dérive de ses tickets : tous READY ⇒ `READY` ; certains READY ⇒ `PARTIALLY_READY`. |
| RG-063 | Un poste ne voit que les tickets de ses stations autorisées. |
| RG-064 | Un ticket est en retard si `now - CreatedAt > max(temps de préparation des lignes) + seuil`. |

### 9.7 Paiement & clôture

| # | Règle |
|---|---|
| RG-070 | La somme des paiements COMPLETED d'une commande ne peut excéder son `Total`. |
| RG-071 | Une commande est CLOSED quand elle est intégralement payée. |
| RG-072 | Une remise requiert `Payments.Discount` et est plafonnée par les settings. |
| RG-073 | Deux requêtes portant la même `Idempotency-Key` créent **un seul** paiement. |
| RG-074 | Un paiement COMPLETED n'est jamais supprimé — uniquement remboursé (`REFUNDED`). |
| RG-075 | Seule une commande SERVED (ou READY selon settings) peut être encaissée. |

### 9.8 Audit

| # | Règle |
|---|---|
| RG-080 | Toute opération sensible (§26.2) écrit un `AuditLog` avec `CorrelationId`. |
| RG-081 | Les `AuditLog` sont append-only ; aucune API d'écriture ou de suppression n'est exposée. |
| RG-082 | Aucun mot de passe, token, hash ou PII sensible n'est écrit dans les logs ou l'audit. |

---

## 10. Workflow complet d'une commande

### 10.1 Vue d'ensemble

```
CLIENT                    API                       DOMAINE / DB              TEMPS RÉEL
  │
  │ 1. Scan QR
  ├─ GET /api/v1/public/qr/{token} ──────────────────►
  │                       Valide le token (hash)
  │                       Trouve/ouvre TableSession
  │                       Émet un guestToken (JWT audience=guest)
  │ ◄──────────── { restaurant, table, sessionId, guestToken, menuVersion }
  │
  │ 2. Consultation du menu
  ├─ GET /api/v1/public/menu  (Bearer guestToken) ───►
  │ ◄──────────── catégories + produits disponibles + modifiers
  │
  │ 3. Panier (100 % local, aucun appel serveur)
  │
  │ 4. (option) Pré-validation
  ├─ POST /api/v1/public/cart/price ─────────────────►
  │                       Recalcule les totaux depuis la DB
  │ ◄──────────── { subtotal, tax, total, unavailableItems[] }
  │
  │ 5. Confirmation                    ┌── TRANSACTION ──────────────────┐
  ├─ POST /api/v1/public/orders ──────►│ a. Vérifier session OPEN/ACTIVE │
  │  Idempotency-Key: <uuid>           │ b. Charger produits (1 requête) │
  │                                    │ c. Vérifier disponibilités      │
  │                                    │ d. Snapshot prix/nom/station    │
  │                                    │ e. Recalculer totaux            │
  │                                    │ f. WaiterId ← assignment ACTIVE │
  │                                    │ g. Créer Order + OrderItems     │
  │                                    │ h. Créer PreparationTickets     │
  │                                    │ i. Créer OrderStatusHistory     │
  │                                    │ j. Créer AuditLog               │
  │                                    │ k. Enregistrer l'idempotency    │
  │                                    └── COMMIT ───────────────────────┘
  │                                                                 │
  │                                          publication APRÈS commit ▼
  │ ◄──────────── 201 { orderId, orderNumber, status, total }
  │                                    restaurant:{id} ← order.created
  │                                    station:{kitchen} ← ticket.created
  │                                    station:{bar}     ← ticket.created
  │                                    user:{waiterId}   ← order.created
  │                                    table:{tableId}   ← order.created
  │
  │ 6. Suivi
  ├─ GET /api/v1/public/orders/{id}  (SignalR groupe table + polling 10 s)
  │ ◄──────────── statut temps réel
```

### 10.2 Détail de la transaction de confirmation

L'ordre des opérations n'est pas négociable :

1. **Valider la session** — OPEN ou ACTIVE, appartenant au restaurant du token.
2. **Charger les produits** en une seule requête `WHERE Id IN (...)` (jamais N+1).
3. **Vérifier** existence, `IsActive`, `IsAvailable`, appartenance au restaurant.
4. **Valider les modifiers** contre les contraintes min/max/required.
5. **Snapshoter** nom, prix unitaire, taux de taxe, station, libellés de modifiers.
6. **Calculer** ligne par ligne : `(prix + Σ deltas) × quantité`, puis taxe, puis totaux.
7. **Résoudre le serveur** : `session.CurrentWaiterId` (peut être null — RG-047).
8. **Générer** `OrderNumber` séquentiel par restaurant/jour.
9. **Persister** Order + Items + Modifiers + StatusHistory.
10. **Grouper les lignes par station** et créer un `PreparationTicket` par groupe.
11. **Écrire** l'`AuditLog` (`ORDER_CREATED`).
12. **Stocker** la réponse dans `IdempotencyRecord`.
13. **COMMIT.**
14. **Puis seulement** : publier les événements SignalR.

### 10.3 Machine à états d'une commande

```
   DRAFT ──────► PENDING ──────► CONFIRMED ──────► IN_PREPARATION
     │             │                 │                    │
     │             │                 │                    ▼
     │             │                 │            PARTIALLY_READY
     │             │                 │                    │
     │             │                 │                    ▼
     │             │                 └──────────────►   READY
     │             │                                      │
     │             │                                      ▼
     │             │                                    SERVED
     │             │                                      │
     │             │                                      ▼
     │             │                                    CLOSED   (terminal)
     │             │
     └─────────────┴──────────────► CANCELLED  (terminal, depuis tout
                                                statut sauf CLOSED)
```

### 10.4 Table des transitions autorisées

| Depuis | Vers autorisés | Acteur | Permission |
|---|---|---|---|
| DRAFT | PENDING, CANCELLED | Client / Serveur | — |
| PENDING | CONFIRMED, CANCELLED | Serveur / auto | `Orders.Update` |
| CONFIRMED | IN_PREPARATION, CANCELLED | Cuisine / Bar | `Kitchen.Manage` / `Bar.Manage` |
| IN_PREPARATION | PARTIALLY_READY, READY, CANCELLED | Cuisine / Bar | idem |
| PARTIALLY_READY | READY, CANCELLED | Cuisine / Bar | idem |
| READY | SERVED, CANCELLED | Serveur | `Orders.Serve` |
| SERVED | CLOSED, CANCELLED | Caissier | `Payments.Create` |
| CLOSED | — (terminal) | — | — |
| CANCELLED | — (terminal) | — | — |

*Note* : `PENDING → CONFIRMED` est automatique si
`RestaurantSettings.OrderRequiresWaiterConfirmation = false` (mode « service rapide »),
sinon le serveur valide la commande client avant envoi en cuisine. C'est un levier de
confiance important pour les établissements réticents à la commande directe.

---

## 11. Workflow serveur / table

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. Connexion (email/mot de passe ou code employé + PIN)          │
│    → JWT + refresh token, claims : restaurantId, role, perms     │
├─────────────────────────────────────────────────────────────────┤
│ 2. Écran « Plan de salle »                                       │
│    GET /api/v1/tables?includeSession=true                        │
│    Vue : n° table · zone · statut · serveur actif · durée · total│
│    Filtres : mes tables / libres / toutes                        │
├─────────────────────────────────────────────────────────────────┤
│ 3. Prendre une table                                             │
│    POST /api/v1/tables/{id}/take   { guestCount }                │
│    ┌── TRANSACTION ────────────────────────────────────────┐     │
│    │ Verrou sur la table (UPDLOCK)                         │     │
│    │ Si session OPEN/ACTIVE → la réutiliser, sinon créer   │     │
│    │ Si assignment ACTIVE existe → 409 TABLE_ALREADY_...   │     │
│    │ Créer ServiceAssignment (ACTIVE)                      │     │
│    │ Écrire History (ASSIGNED)                             │     │
│    │ Table.Status = OCCUPIED                               │     │
│    │ AuditLog                                              │     │
│    └── COMMIT ─────────────────────────────────────────────┘     │
│    → SignalR : table.assigned sur restaurant:{id}                │
├─────────────────────────────────────────────────────────────────┤
│ 4. Écran « Mes tables »                                          │
│    GET /api/v1/waiter/me/tables                                  │
│    Par table : couverts · nb commandes · total · durée · statut  │
│    Badge temps réel : « commande prête à servir »                │
├─────────────────────────────────────────────────────────────────┤
│ 5. Suivi des préparations                                        │
│    SignalR user:{waiterId} ← order.ready                         │
│    Notification sonore + visuelle                                │
├─────────────────────────────────────────────────────────────────┤
│ 6. Servir                                                        │
│    POST /api/v1/orders/{id}/serve                                │
│    → Status = SERVED, ServedBy = moi, ServedAt = now             │
│    → OrderStatusHistory + AuditLog                               │
├─────────────────────────────────────────────────────────────────┤
│ 7. Demander l'addition                                           │
│    POST /api/v1/table-sessions/{id}/request-bill                 │
│    → notification au caissier (rôle CASHIER du restaurant)       │
├─────────────────────────────────────────────────────────────────┤
│ 8. Libérer / clôturer                                            │
│    POST /api/v1/tables/{id}/release       (fin d'affectation)    │
│    POST /api/v1/table-sessions/{id}/close (fin de session)       │
│    → Vérifie RG-022, Table → CLEANING → AVAILABLE                │
└─────────────────────────────────────────────────────────────────┘
```

**Prise de service optionnelle par le client** : si un client scanne le QR d'une table
sans serveur assigné, la commande est créée avec `WaiterId = null` et la table apparaît
en tête de liste « à prendre en charge » pour tous les serveurs connectés — le premier
qui la prend récupère la responsabilité des commandes suivantes (pas des précédentes).

---

## 12. Workflow transfert de table

### 12.1 Scénario de référence (cahier des charges §34)

```
19:00   Jean prend la table 12
        → TableSession #456 (ACTIVE)
        → ServiceAssignment A1 : Jean, ACTIVE
        → History : ASSIGNED, previous=null, new=Jean, by=Jean

19:15   Commande #1001 créée
        → Order.WaiterId = Jean          ◄── GELÉ POUR TOUJOURS

19:45   Jean transfère la table à Marie
        ┌── TRANSACTION ──────────────────────────────────┐
        │ Vérifier : Jean est bien le serveur actif       │
        │ Vérifier : Marie est WAITER, active, même resto │
        │ A1.Status = TRANSFERRED, UnassignedAt = 19:45   │
        │ A2 : Marie, ACTIVE, AssignedAt = 19:45          │
        │ History : TRANSFERRED, prev=Jean, new=Marie,    │
        │           by=Jean, at=19:45, reason="fin de     │
        │           service"                              │
        │ AuditLog : TABLE_TRANSFERRED                    │
        └── COMMIT ───────────────────────────────────────┘
        → SignalR : table.transferred → restaurant, Jean, Marie

20:00   Commande #1002 créée
        → Order.WaiterId = Marie         ◄── nouveau serveur actif

RÉSULTAT :
  Commande #1001 → Jean   (inchangée, à jamais)
  Commande #1002 → Marie
  Historique complet et interrogeable à la seconde près.
```

### 12.2 Répondre à « qui était responsable de la table 8 à 20h15 ? »

Deux chemins possibles, tous deux fiables :

**Chemin A — via les assignments (état matérialisé)**
```sql
SELECT sa.WaiterId
FROM ServiceAssignments sa
JOIN TableSessions ts ON ts.Id = sa.TableSessionId
WHERE sa.RestaurantId = @rid
  AND sa.TableId      = @tableId
  AND sa.AssignedAt  <= @at
  AND (sa.UnassignedAt IS NULL OR sa.UnassignedAt > @at);
```

**Chemin B — via l'historique (trace immuable)**
```sql
SELECT TOP 1 NewWaiterId
FROM ServiceAssignmentHistory
WHERE RestaurantId = @rid AND TableId = @tableId AND ChangedAt <= @at
ORDER BY ChangedAt DESC;
```

Le chemin A est l'index de production ; le chemin B est la **preuve d'audit** — il reste
valide même si les assignments étaient corrompues, et un test d'intégration vérifie en
permanence que les deux chemins concordent.

### 12.3 Variantes

| Variante | Comportement |
|---|---|
| Transfert par un MANAGER | Autorisé sans être le serveur actif ; `ChangedBy` = manager, `Action` = REASSIGNED |
| Transfert vers soi-même | Rejeté (`SAME_WAITER_TRANSFER`, 400) |
| Transfert d'une table sans serveur | Traité comme `ASSIGNED`, pas comme `TRANSFERRED` |
| Transfert en masse (fin de shift) | `POST /api/v1/service-assignments/bulk-transfer` — une transaction, N lignes d'historique |
| Transfert d'une session close | Rejeté (`SESSION_CLOSED`, 409) |

---

## 13. Workflow cuisine

### 13.1 Écran KDS

```
┌──────────────────────────────────────────────────────────────────────┐
│  CUISINE — Restaurant Le Palmier          🟢 connecté      19:42:07  │
├──────────────┬──────────────┬──────────────┬─────────────────────────┤
│  NOUVELLES 3 │ EN COURS  2  │  PRÊTES   4  │  ⚠ EN RETARD  1         │
├──────────────┼──────────────┼──────────────┼─────────────────────────┤
│ ┌──────────┐ │ ┌──────────┐ │ ┌──────────┐ │ ┌────────────────────┐  │
│ │ #1045    │ │ │ #1041    │ │ │ #1038    │ │ │ #1033    ⏱ 24 min │  │
│ │ Table 12 │ │ │ Table 5  │ │ │ Table 9  │ │ │ Table 2            │  │
│ │ Jean     │ │ │ Marie    │ │ │ Jean     │ │ │ Paul               │  │
│ │ ⏱ 0:42   │ │ │ ⏱ 6:10   │ │ │ ⏱ 11:20  │ │ │                    │  │
│ ├──────────┤ │ ├──────────┤ │ ├──────────┤ │ ├────────────────────┤  │
│ │ 2× Pizza │ │ │ 1× Poulet│ │ │ 3× Riz   │ │ │ 2× Poisson grillé  │  │
│ │   Margh. │ │ │   grillé │ │ │ 1× Salade│ │ │   sans sel         │  │
│ │ 1× Burger│ │ │ ▸ sans   │ │ │          │ │ │                    │  │
│ │ ▸ sans   │ │ │   piment │ │ │          │ │ │                    │  │
│ │   oignon │ │ │          │ │ │          │ │ │                    │  │
│ ├──────────┤ │ ├──────────┤ │ ├──────────┤ │ ├────────────────────┤  │
│ │ ACCEPTER │ │ │ TERMINER │ │ │  RETIRER │ │ │      TERMINER      │  │
│ └──────────┘ │ └──────────┘ │ └──────────┘ │ └────────────────────┘  │
└──────────────┴──────────────┴──────────────┴─────────────────────────┘
```

### 13.2 Cycle de vie d'un ticket

```
  NEW ──[Accepter]──► ACCEPTED ──[Commencer]──► IN_PREPARATION
                                                      │
                                              [Marquer prêt]
                                                      ▼
                                                    READY
                                                      │
                                          [Retiré par le serveur]
                                                      ▼
                                                   PICKED_UP
```

Effets de bord :

- `ACCEPTED` → si c'est le 1ᵉʳ ticket accepté, `Order.Status = IN_PREPARATION`,
  `Order.StartedAt = now`.
- `READY` → si tous les tickets de la commande sont READY : `Order.Status = READY`,
  `ReadyAt = now`, SignalR vers `user:{waiterId}`. Sinon `PARTIALLY_READY`.

### 13.3 Contraintes d'interface (non négociables)

| Contrainte | Raison |
|---|---|
| Zone tactile ≥ 64 × 64 px | mains grasses, gants, gestes rapides |
| Texte produit ≥ 20 px, table ≥ 28 px | lisibilité à 2 m d'un écran mural |
| Codage couleur du temps : vert < 8 min, orange 8-15, rouge > 15 (paramétrable) | perception périphérique |
| Aucune modale bloquante | on ne peut pas fermer un dialogue avec les mains occupées |
| Chrono côté client, resynchronisé sur l'heure serveur | pas d'appel réseau par seconde |
| Mode dégradé si SignalR tombe (polling 15 s) | un KDS figé arrête le service |
| Confirmation à deux temps pour les actions destructives | éviter les fausses manipulations |

---

## 14. Workflow bar

Même moteur, même composant KDS, **paramétrage différent** :

| Aspect | Cuisine | Bar |
|---|---|---|
| Station filtrée | `KITCHEN` | `BAR` |
| Groupe SignalR | `station:{kitchenStationId}` | `station:{barStationId}` |
| Seuil de retard | 15 min | 5 min (une boisson attend moins) |
| Densité d'affichage | 4 colonnes | 6 colonnes (tickets plus courts) |
| Étape « Accepter » | oui | optionnelle (souvent NEW → READY direct) |
| Regroupement | par ticket | option « par produit » pour préparer 6 mojitos d'un coup |

**Point clé** : une commande contenant une pizza et un mojito produit **deux tickets
indépendants**. Le bar n'a aucune visibilité sur la pizza, la cuisine aucune sur le
mojito. Chacun avance à son rythme ; la commande passe `READY` quand les deux le sont,
et `PARTIALLY_READY` entre-temps — ce qui permet au serveur de servir les boissons
pendant que le plat cuit.

---

## 15. Workflow caisse

```
┌────────────────────────────────────────────────────────────────────┐
│ 1. File d'attente d'encaissement                                   │
│    GET /api/v1/cashier/pending-sessions                            │
│    Tables avec commandes SERVED non payées + demandes d'addition   │
├────────────────────────────────────────────────────────────────────┤
│ 2. Ouvrir une session                                              │
│    GET /api/v1/table-sessions/{id}/bill                            │
│    → Commandes, lignes, sous-total, taxes, service, total, payé    │
├────────────────────────────────────────────────────────────────────┤
│ 3. Remise (optionnelle)                                            │
│    POST /api/v1/orders/{id}/discount  { type, value, reason }      │
│    → permission Payments.Discount + plafond des settings           │
│    → recalcul serveur intégral, audit                              │
├────────────────────────────────────────────────────────────────────┤
│ 4. Encaisser                                                       │
│    POST /api/v1/payments   Idempotency-Key: <uuid>                 │
│    { orderId | tableSessionId, amount, method: CASH, ... }         │
│    ┌── TRANSACTION ──────────────────────────────────────────┐     │
│    │ Vérifier idempotence (clé déjà vue → renvoyer la même   │     │
│    │   réponse, ne rien créer)                               │     │
│    │ Recharger la commande (verrou optimiste RowVersion)     │     │
│    │ Vérifier Σ paiements + montant ≤ Total                  │     │
│    │ IPaymentProvider.ProcessAsync(...)  → CASH = immédiat   │     │
│    │ Créer Payment (COMPLETED), ProcessedBy = caissier       │     │
│    │ Si soldé : Order.Status = CLOSED, ClosedBy, ClosedAt    │     │
│    │ OrderStatusHistory + AuditLog                           │     │
│    │ Enregistrer IdempotencyRecord                           │     │
│    └── COMMIT ───────────────────────────────────────────────┘     │
│    → SignalR : payment.completed, order.closed                     │
├────────────────────────────────────────────────────────────────────┤
│ 5. Reçu                                                            │
│    GET /api/v1/payments/{id}/receipt   (HTML imprimable + JSON)    │
├────────────────────────────────────────────────────────────────────┤
│ 6. Clôturer la session                                             │
│    POST /api/v1/table-sessions/{id}/close                          │
│    → RG-022, assignments ENDED, table CLEANING, jeton invité mort  │
└────────────────────────────────────────────────────────────────────┘
```

### 15.1 Abstraction de paiement

```
IPaymentProvider                          (signature conceptuelle,
  Method : PaymentMethod                   aucune implémentation en Phase 1)
  InitiateAsync(PaymentRequest, ct) → PaymentResult
  ConfirmAsync(transactionRef, ct)  → PaymentResult
  RefundAsync(transactionRef, Money, ct) → PaymentResult
```

MVP : un seul provider `CashPaymentProvider` (confirmation immédiate, aucun appel
réseau). Résolution par `IPaymentProviderResolver` sur `PaymentMethod`.
MonCash / NatCash / Stripe s'ajoutent plus tard **sans toucher au domaine** : nouvelle
implémentation + enregistrement DI + webhook de confirmation.

**Aucune simulation de paiement réel** n'est implémentée (règle du cahier des charges).

---

## 16. Modèle multi-tenant

### 16.1 Stratégie retenue — ADR-005

**Base unique, schéma partagé, discriminant `RestaurantId`, filtres globaux EF Core.**

| Option | Isolation | Coût | Verdict |
|---|---|---|---|
| Base par tenant | Maximale | Migrations × N, coût SQL Server par base, provisioning lourd | Rejeté au MVP |
| Schéma par tenant | Forte | Complexité EF importante | Rejeté |
| **Discriminant partagé** | **Logique, applicative** | **Faible, migrations uniques** | **Retenu** |

*Conséquence assumée* : l'isolation repose sur du code. Elle doit donc être **défendue à
trois niveaux indépendants** — un seul niveau serait irresponsable.

### 16.2 Défense en profondeur

```
NIVEAU 1 — RÉSOLUTION (middleware)
  ITenantContext est peuplé UNIQUEMENT depuis le claim `restaurant_id` du JWT.
  Aucune lecture de header, query string, route ou body. Jamais.
  Pour un jeton invité : le restaurantId vient de la TableSession du jeton.

NIVEAU 2 — LECTURE (EF Core global query filter)
  modelBuilder.Entity<T>().HasQueryFilter(
      e => e.RestaurantId == _tenant.RestaurantId);
  Appliqué automatiquement à toute entité implémentant ITenantEntity.
  Une requête oubliant le filtre devient littéralement impossible à écrire.

NIVEAU 3 — ÉCRITURE (SaveChangesInterceptor)
  À chaque SaveChanges :
    - entités Added   → RestaurantId forcé au tenant courant
    - entités Modified/Deleted → si RestaurantId ≠ tenant courant
      ⇒ TenantViolationException + log SECURITY critique + audit
```

Un quatrième filet existe : les tests d'intégration de cloisonnement (§27), qui
échouent la CI si un endpoint fuit.

### 16.3 Cas particuliers

| Cas | Traitement |
|---|---|
| SUPER_ADMIN | `RestaurantId` nul dans le JWT ; les filtres sont désactivés explicitement via `IgnoreQueryFilters()` dans des queries d'administration dédiées, **jamais** globalement |
| Utilisateur multi-restaurants (franchise, futur) | Le JWT porte le restaurant **actif** ; changer de restaurant = ré-émettre un jeton via `POST /auth/switch-restaurant` |
| Endpoints publics (QR) | Le tenant est résolu depuis le token QR, avant tout accès aux données |
| Jobs de fond | `TenantScope.For(restaurantId)` explicite, jamais d'exécution « sans tenant » |

### 16.4 `TenantId` vs `RestaurantId`

- `TenantId` = **le client SaaS** (l'entreprise qui paie l'abonnement).
- `RestaurantId` = **l'établissement** (un point de vente).

Un tenant peut posséder plusieurs restaurants (préparation franchise / multi-sites).
Au MVP la relation est 1-1 en pratique, mais **le champ existe dès le jour 1** :
l'ajouter après coup imposerait une migration de toutes les tables métier.
Le cloisonnement opérationnel se fait sur `RestaurantId` ; la facturation SaaS se fera
sur `TenantId`.

---

## 17. Modèle de sécurité

### 17.1 Authentification

| Élément | Choix | Justification |
|---|---|---|
| Hachage mot de passe | ASP.NET Identity (PBKDF2, HMAC-SHA512, 100k+ itérations) | Éprouvé, salage automatique, mise à niveau transparente |
| Access token | JWT, 15 min, HS256 (clé ≥ 256 bits) ou RS256 | Court = fenêtre de compromission réduite |
| Refresh token | Opaque, 256 bits, **hashé en base**, 7-30 jours | Un dump de base ne donne aucun token utilisable |
| Rotation | À chaque refresh ; l'ancien est révoqué (`ReplacedByTokenId`) | Détection de rejeu |
| Détection de réutilisation | Réutiliser un token révoqué ⇒ révocation de **toute la chaîne** + alerte | Contre le vol de refresh token |
| Jeton invité (QR) | JWT audience `guest`, 4 h, claims `sessionId`+`tableId`+`restaurantId`, **aucune permission staff** | Surface d'attaque minimale |
| Verrouillage compte | 5 échecs ⇒ 15 min | Anti brute-force |
| Connexion terrain | Code employé + PIN (option), mêmes protections | Un serveur ne tape pas un email sur un téléphone en plein service |

### 17.2 Claims du JWT staff

```json
{
  "sub": "<userId>",
  "email": "jean@palmier.ht",
  "restaurant_id": "<restaurantId>",
  "tenant_id": "<tenantId>",
  "staff_id": "<staffProfileId>",
  "role": ["WAITER"],
  "perm": ["Orders.View", "Orders.Create", "Orders.Serve",
           "Tables.View", "Tables.Assign", "Tables.Transfer"],
  "exp": 1735689600,
  "jti": "<tokenId>"
}
```

*Attention taille* : si la liste de permissions dépasse ~30 entrées, on bascule sur un
claim de version (`perm_v`) + cache serveur, pour ne pas gonfler chaque requête.

### 17.3 Autorisation

Trois couches complémentaires :

1. **Policy-based** : `[Authorize(Policy = "Orders.Cancel")]` — une policy générée par
   permission, évaluée sur les claims.
2. **Resource-based** : « ce serveur peut-il transférer *cette* table ? » — un
   `IAuthorizationHandler` qui charge la ressource et vérifie la propriété métier.
   Les policies seules ne suffisent pas : `Tables.Transfer` autorise l'action, pas la cible.
3. **Domaine** : l'agrégat refuse l'opération illégale même si l'appelant est autorisé
   (ceinture et bretelles).

### 17.4 Matrice rôles × permissions

| Permission | SUPER<br>ADMIN | RESTAURANT<br>ADMIN | MANAGER | CASHIER | WAITER | KITCHEN | BAR | STAFF |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Orders.View | ✓ | ✓ | ✓ | ✓ | ✓* | ✓* | ✓* | — |
| Orders.Create | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | — |
| Orders.Update | ✓ | ✓ | ✓ | ✓ | ✓* | — | — | — |
| Orders.Cancel | ✓ | ✓ | ✓ | — | ✓* | — | — | — |
| Orders.Serve | ✓ | ✓ | ✓ | — | ✓ | — | — | — |
| Tables.View | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | ✓ |
| Tables.Assign | ✓ | ✓ | ✓ | — | ✓ | — | — | — |
| Tables.Transfer | ✓ | ✓ | ✓ | — | ✓* | — | — | — |
| Menu.View | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Menu.Manage | ✓ | ✓ | ✓ | — | — | — | — | — |
| Kitchen.View | ✓ | ✓ | ✓ | — | ✓ | ✓ | — | — |
| Kitchen.Manage | ✓ | ✓ | ✓ | — | — | ✓ | — | — |
| Bar.View | ✓ | ✓ | ✓ | — | ✓ | — | ✓ | — |
| Bar.Manage | ✓ | ✓ | ✓ | — | — | — | ✓ | — |
| Payments.View | ✓ | ✓ | ✓ | ✓ | ✓* | — | — | — |
| Payments.Create | ✓ | ✓ | ✓ | ✓ | — | — | — | — |
| Payments.Discount | ✓ | ✓ | ✓ | — | — | — | — | — |
| Reports.View | ✓ | ✓ | ✓ | — | — | — | — | — |
| Staff.Manage | ✓ | ✓ | ✓ | — | — | — | — | — |
| Restaurant.Manage | ✓ | ✓ | — | — | — | — | — | — |
| Audit.View | ✓ | ✓ | — | — | — | — | — | — |

`✓*` = restreint à son propre périmètre (ses tables, sa station, ses commandes),
appliqué par autorisation *resource-based*, pas seulement par policy.

### 17.5 Couverture OWASP Top 10

| Risque | Contre-mesure |
|---|---|
| A01 Broken Access Control | 3 niveaux de tenancy + policies + resource handlers + tests de cloisonnement en CI |
| A02 Cryptographic Failures | HTTPS/HSTS, tokens QR et refresh hashés, secrets hors Git, PBKDF2 |
| A03 Injection | EF Core paramétré exclusivement ; aucun SQL concaténé ; validation stricte |
| A04 Insecure Design | Invariants modélisés dans le domaine, revue de menace par workflow |
| A05 Security Misconfiguration | CORS en liste blanche, headers de sécurité, Swagger désactivé en prod, pas de stack trace |
| A06 Vulnerable Components | `dotnet list package --vulnerable` + `npm audit` bloquants en CI, Dependabot |
| A07 Auth Failures | Lockout, rotation + détection de rejeu, expiration courte, révocation |
| A08 Data Integrity | Idempotence, RowVersion, transactions, audit append-only |
| A09 Logging Failures | Serilog structuré, CorrelationId, événements de sécurité tracés, jamais de secret loggé |
| A10 SSRF | Aucun appel sortant piloté par l'utilisateur ; URLs de providers en configuration |

### 17.6 Rate limiting

| Endpoint | Limite | Fenêtre | Clé |
|---|---|---|---|
| `POST /auth/login` | 5 | 1 min | IP + email |
| `POST /auth/refresh` | 10 | 1 min | IP |
| `GET /public/qr/{token}` | 20 | 1 min | IP |
| `POST /public/orders` | 5 | 1 min | sessionId |
| API staff (global) | 300 | 1 min | userId |
| KDS polling | 60 | 1 min | userId |

Implémenté avec le rate limiting natif d'ASP.NET Core (fenêtre glissante).

---

## 18. Architecture SignalR

### 18.1 Hub unique, groupes multiples

**Décision ADR-006** : un seul `RestaurantHub` (`/hubs/restaurant`), le routage se fait
par groupes. Plusieurs hubs multiplieraient les connexions WebSocket par client sans
bénéfice (le KDS regarde une station, mais le serveur regarde ses tables *et* le
restaurant).

### 18.2 Groupes

| Groupe | Membres | Événements reçus |
|---|---|---|
| `restaurant:{restaurantId}` | tout le staff authentifié du restaurant | vue d'ensemble : commandes, tables, paiements |
| `station:{stationId}` | KITCHEN / BAR autorisés sur cette station | tickets de cette station uniquement |
| `table:{tableId}` | clients invités de la session en cours + serveur | statut de leurs commandes |
| `user:{userId}` | l'utilisateur lui-même | notifications personnelles |
| `role:{restaurantId}:{role}` | ex. tous les CASHIER | demandes d'addition |

**L'abonnement aux groupes est décidé côté serveur** dans `OnConnectedAsync`, d'après
les claims. Un client ne peut **jamais** demander à rejoindre un groupe arbitraire —
sans quoi le cloisonnement multi-tenant serait contournable en une ligne de JavaScript.

### 18.3 Catalogue d'événements

| Événement | Payload (léger) | Groupes cibles |
|---|---|---|
| `order.created` | orderId, orderNumber, tableId, tableNumber, sessionId, waiterId, total, itemCount, createdAt | restaurant, table, user:waiter |
| `order.confirmed` | orderId, status, confirmedAt | restaurant, table |
| `order.status_changed` | orderId, previousStatus, newStatus, changedAt | restaurant, table, user:waiter |
| `order.ready` | orderId, orderNumber, tableNumber, readyAt | restaurant, user:waiter |
| `order.served` | orderId, servedBy, servedAt | restaurant, table |
| `order.cancelled` | orderId, reason, cancelledBy | restaurant, table, stations |
| `ticket.created` | ticketId, orderId, stationId, itemCount, createdAt | station |
| `ticket.status_changed` | ticketId, status, changedAt | station, restaurant |
| `payment.completed` | paymentId, orderId, amount, method | restaurant, role:CASHIER |
| `table.assigned` | tableId, sessionId, waiterId, assignedAt | restaurant, user:waiter |
| `table.transferred` | tableId, sessionId, previousWaiterId, newWaiterId | restaurant, les deux serveurs |
| `table.released` | tableId, sessionId | restaurant |
| `session.closed` | sessionId, tableId, closedAt | restaurant, table |
| `bill.requested` | sessionId, tableId, tableNumber | role:CASHIER, restaurant |

**Principe** : le payload est un **signal**, pas la donnée de référence. Il contient
juste assez pour un affichage optimiste et un identifiant pour recharger.

### 18.4 Résilience — le point critique

> « NE PAS dépendre exclusivement de SignalR pour la cohérence des données. »

```
Connexion SignalR ─┐
                   ├─► État de l'écran ◄─┐
API REST (vérité) ─┘                     │
                                          │
  Machine à états du client :             │
                                          │
  CONNECTING ──► CONNECTED ──► DISCONNECTED (bandeau orange)
       ▲              │              │
       │              │              ├─► retry exponentiel 0/2/5/10/30 s
       │              │              │
       └──────────────┴──────────────┘
                      │
              RECONNECTED
                      │
                      ▼
     ┌─────────────────────────────────────────┐
     │ RESYNC OBLIGATOIRE                      │
     │ queryClient.invalidateQueries()         │
     │ + GET /kitchen/snapshot (ou équivalent) │
     │ → l'écran repart de la vérité serveur   │
     └─────────────────────────────────────────┘

Filet permanent : polling de sécurité (15 s KDS, 30 s dashboards)
                  même quand SignalR est connecté.
```

Chaque écran temps réel expose un endpoint `.../snapshot` qui renvoie **l'état complet**
en une requête. C'est ce qui rend le test 10 (§27.5) réalisable et le système utilisable
sur un réseau instable.

### 18.5 Montée en charge

Une instance API supporte quelques milliers de connexions. Au-delà :
`AddStackExchangeRedisBackplane()`, sans modification du code applicatif — c'est
précisément ce que protège l'abstraction `IRealtimeNotifier`.

---

## 19. Architecture API

### 19.1 Conventions

| Aspect | Convention |
|---|---|
| Versioning | Préfixe d'URL `/api/v1/...` |
| Format | JSON, camelCase, dates ISO 8601 UTC (`DateTimeOffset`) |
| Nommage | Ressources au pluriel, actions métier en sous-ressource verbale (`/orders/{id}/serve`) |
| Verbes | GET (lecture), POST (création + actions), PUT (remplacement), PATCH (partiel), DELETE (désactivation logique) |
| Corrélation | `X-Correlation-Id` accepté en entrée, toujours renvoyé |
| Idempotence | `Idempotency-Key` sur POST sensibles |
| Concurrence | `If-Match` / ETag sur les ressources versionnées |
| Exposition | DTOs uniquement — **aucune entité EF Core exposée** |

### 19.2 Enveloppe de réponse

Succès :
```json
{ "success": true, "data": { }, "traceId": "0HN7..." }
```

Liste paginée :
```json
{
  "success": true,
  "data": {
    "items": [],
    "page": 1, "pageSize": 20,
    "totalCount": 137, "totalPages": 7,
    "hasNext": true, "hasPrevious": false
  },
  "traceId": "0HN7..."
}
```

Erreur :
```json
{
  "success": false,
  "message": "La commande demandée est introuvable.",
  "code": "ORDER_NOT_FOUND",
  "errors": { "orderId": ["Identifiant inconnu."] },
  "traceId": "0HN7..."
}
```

### 19.3 Codes d'erreur métier

| Code | HTTP | Signification |
|---|---|---|
| `VALIDATION_ERROR` | 400 | Échec FluentValidation |
| `UNAUTHORIZED` | 401 | Jeton absent, invalide ou expiré |
| `FORBIDDEN` | 403 | Permission insuffisante |
| `ORDER_NOT_FOUND` | 404 | Commande inexistante ou hors tenant |
| `QR_CODE_INVALID` | 404 | Token QR inconnu, expiré ou désactivé |
| `TABLE_ALREADY_ASSIGNED` | 409 | Table déjà prise par un autre serveur |
| `SESSION_ALREADY_OPEN` | 409 | Une session est déjà ouverte sur la table |
| `SESSION_CLOSED` | 409 | Session close, opération impossible |
| `PRODUCT_UNAVAILABLE` | 409 | Produit indisponible au moment de la commande |
| `INVALID_ORDER_STATUS_TRANSITION` | 409 | Transition de statut illégale |
| `CONCURRENCY_CONFLICT` | 409 | RowVersion périmé, recharger |
| `PAYMENT_EXCEEDS_TOTAL` | 409 | Somme des paiements > total |
| `IDEMPOTENCY_KEY_REUSED` | 409 | Même clé, corps différent |
| `SESSION_HAS_UNPAID_ORDERS` | 409 | Clôture refusée |
| `RATE_LIMIT_EXCEEDED` | 429 | Quota dépassé |
| `INTERNAL_ERROR` | 500 | Erreur inattendue (message générique en prod) |

### 19.4 Pipeline de requête

```
Requête HTTPS
   ▼ CorrelationIdMiddleware      → génère/propage X-Correlation-Id
   ▼ SerilogRequestLogging        → enrichit : user, restaurant, endpoint, durée
   ▼ ExceptionHandlingMiddleware  → mappe exceptions → enveloppe d'erreur
   ▼ RateLimiter                  → quotas par politique
   ▼ Authentication (JWT)         → valide le jeton
   ▼ TenantResolutionMiddleware   → peuple ITenantContext depuis les claims
   ▼ Authorization                → policies + resource handlers
   ▼ IdempotencyFilter            → court-circuite si clé déjà traitée
   ▼ Controller → Handler
        ▼ ValidationBehavior      → FluentValidation
        ▼ TransactionBehavior     → begin/commit, dispatch des events APRÈS commit
        ▼ Domaine + Repositories
   ▼ Réponse (enveloppe)
```

---

## 20. Architecture frontend

### 20.1 Trois applications, un seul socle

| Application | Route racine | Auth | Cible | Contrainte |
|---|---|---|---|---|
| **Client** (PWA) | `/order/t/{token}` | jeton invité | smartphone | une main, réseau faible, zéro formation |
| **Staff** | `/app/*` | JWT staff | téléphone / tablette / desktop | rapidité, rôle-dépendant |
| **Display** (KDS/BDS) | `/display/*` | JWT station | grand écran | lisibilité à distance, plein écran |

Une seule base de code Vite, un seul design system, trois entrées de routage.
Le *code splitting* par route évite de charger le back-office sur le téléphone du client.

### 20.2 Structure

```
src/
 ├── app/                 providers, router, layouts, error boundaries
 ├── components/ui/       design system (Button, Card, Sheet, Badge, Table…)
 ├── features/
 │    ├── auth/           login, refresh silencieux, garde de route
 │    ├── guest/          scan, menu, panier, suivi  ◄── app CLIENT
 │    ├── tables/         plan de salle, prise, transfert
 │    ├── menu/           gestion catalogue
 │    ├── orders/         listes, détail, actions
 │    ├── kitchen/        KDS
 │    ├── bar/            BDS
 │    ├── waiter/         mes tables
 │    ├── cashier/        caisse, encaissement, reçus
 │    ├── reports/        rapports & graphiques
 │    └── admin/          restaurant, staff, settings
 ├── hooks/               useAuth, useRealtime, usePermissions, useCountdown
 ├── services/            apiClient (fetch + refresh auto), signalr client
 ├── lib/                 money, date, qr, storage, format
 ├── types/               types API générés depuis OpenAPI
 └── routes/              définitions de routes par application
```

### 20.3 Gestion d'état — ADR-007

| Type d'état | Outil | Pourquoi |
|---|---|---|
| Données serveur | **TanStack Query** | cache, invalidation, refetch, retry, offline — 90 % de l'état |
| Panier client | **Zustand + localStorage** | purement local, doit survivre à un rechargement de page |
| Formulaires | **React Hook Form + Zod** | validation partagée avec les contrats API |
| Session/auth | **Context + refresh silencieux** | lu partout, écrit rarement |
| Temps réel | **SignalR → invalidation TanStack Query** | une seule source de vérité |

**Règle d'or** : SignalR ne remplit **jamais** le cache directement avec le payload de
l'événement. Il **invalide** la query concernée ; TanStack Query recharge depuis l'API.
Le payload sert uniquement à un affichage optimiste immédiat (badge, son). Cela garantit
qu'un événement perdu, dupliqué ou arrivé dans le désordre ne peut pas corrompre l'écran.

### 20.4 PWA

- `manifest.webmanifest` : nom, icônes, `display: standalone`, couleur de thème.
- Service worker (Workbox) : *cache-first* sur les assets et images de produits,
  *network-first* sur le menu, **network-only** sur les commandes et paiements.
- Fallback hors ligne : le menu déjà consulté reste lisible.
- **Interdit** : confirmer une commande hors ligne. Le prix et la disponibilité doivent
  être validés par le serveur — une file d'attente hors ligne créerait des commandes à
  des prix périmés sur des produits épuisés (règle §67 du cahier des charges).

### 20.5 Direction artistique

Un système POS n'est pas un site web. Les partis pris :

- **Sombre par défaut** pour KDS/BDS (salle peu éclairée, écran allumé 14 h/jour) ;
  clair pour l'admin et le client.
- **Densité élevée** côté staff, **aération** côté client.
- **Une couleur = un statut**, jamais décorative : gris = attente, bleu = en cours,
  ambre = attention, vert = prêt, rouge = retard/annulé.
- Typographie à chiffres tabulaires pour tous les montants (alignement en colonne).
- Cibles tactiles ≥ 48 px staff, ≥ 64 px KDS.
- Zéro dépendance à un template : composants sur mesure sur Tailwind + tokens CSS.
- Accessibilité : contraste AA minimum, focus visible, navigation clavier complète sur
  la caisse (un caissier rapide n'utilise pas la souris).

---

## 21. Proposition MCD

### 21.1 Modèle conceptuel (entités et cardinalités)

```
        ┌──────────────┐
        │   TENANT     │
        └──────┬───────┘
               │ 1,n possède
        ┌──────▼───────┐ 1,1 ─── paramétré par ─── 1,1 ┌──────────────────┐
        │  RESTAURANT  │                               │ RESTAURANT_      │
        │              │                               │ SETTINGS         │
        └──┬─┬─┬─┬─┬─┬─┘                               └──────────────────┘
           │ │ │ │ │ │
           │ │ │ │ │ └─1,n─► AUDIT_LOG
           │ │ │ │ └───1,n─► STATION ◄─0,n─ préparé à ─1,1─ PRODUCT
           │ │ │ └─────1,n─► MENU_CATEGORY ─1,n─ contient ─0,n─► PRODUCT
           │ │ │                                          │ 1,n
           │ │ │                                          ▼ 0,n
           │ │ │                                    PRODUCT_MODIFIER
           │ │ │                                          │ 1,n
           │ │ │                                          ▼ 0,n
           │ │ │                                    MODIFIER_OPTION
           │ │ └───────1,n─► STAFF_PROFILE ─1,1─ est ─1,1─► APP_USER
           │ └─────────1,n─► TABLE_ZONE ─1,n─ regroupe ─0,n─► RESTAURANT_TABLE
           └───────────1,n─► RESTAURANT_TABLE
                                   │
                    ┌──────────────┼──────────────┐
              1,n   │              │ 1,n          │
              ▼     ▼              ▼              │
        TABLE_QR_CODE        TABLE_SESSION        │
        (1 seul actif)              │             │
                        ┌───────────┼─────────────┘
                  1,n   │           │ 0,n
                        ▼           ▼
          SERVICE_ASSIGNMENT      ORDER
          (1 seule ACTIVE)          │
                 │                  ├─1,n─► ORDER_ITEM ─0,n─► ORDER_ITEM_MODIFIER
              0,n│                  ├─1,n─► ORDER_STATUS_HISTORY
                 ▼                  ├─0,n─► PREPARATION_TICKET ─1,n─► PREP_TICKET_ITEM
        SERVICE_ASSIGNMENT_         └─0,n─► PAYMENT
        HISTORY                              │ 0,n
                                             ▼ 1,1
                                        STAFF_PROFILE (encaisseur)
```

### 21.2 Dictionnaire des associations porteuses de sens

| Association | Cardinalité | Sens métier |
|---|---|---|
| Table — TableSession | 1,n / 1,1 | Une table vit plusieurs épisodes de service |
| TableSession — ServiceAssignment | 1,n / 1,1 | Une session peut changer de serveur |
| ServiceAssignment — StaffProfile | 0,n / 1,1 | Un serveur sert plusieurs tables |
| TableSession — Order | 0,n / 1,1 | Une session porte toutes les commandes du groupe |
| Order — StaffProfile (WaiterId) | 0,n / 0,1 | **Fait historique gelé**, pas l'état courant |
| Order — PreparationTicket | 0,n / 1,1 | Une commande est éclatée par station |
| Product — Station | 0,n / 1,1 | Un produit est préparé à une station et une seule |
| Order — Payment | 0,n / 1,1 | Paiement partiel / multiple possible |

---

## 22. Proposition MLD

Notation : **PK** clé primaire · *FK* clé étrangère · `U` unique · `I` index ·
`UF` index unique filtré.

### 22.1 Tenancy & identité

```
Restaurants(
  Id PK uniqueidentifier, TenantId uniqueidentifier I, Name nvarchar(200),
  Slug nvarchar(60) U, Address nvarchar(400), Phone nvarchar(30),
  Email nvarchar(200), LogoUrl nvarchar(500), Currency char(3),
  Timezone nvarchar(60), IsActive bit, CreatedAt, UpdatedAt )

RestaurantSettings(
  RestaurantId PK/FK→Restaurants, DefaultTaxRate decimal(5,4),
  TaxIncludedInPrice bit, ServiceChargeRate decimal(5,4),
  OrderRequiresWaiterConfirmation bit, AllowGuestOrdering bit,
  MaxOpenOrdersPerSession int, KdsWarningThresholdMinutes int,
  KdsLateThresholdMinutes int, QrTokenRotationDays int,
  MaxDiscountPercentage decimal(5,2), ReceiptHeader nvarchar(500),
  ReceiptFooter nvarchar(500), PrinterConfigJson nvarchar(max),
  OpeningHoursJson nvarchar(max) )

AspNetUsers( Id PK, ... Identity ..., RestaurantId FK NULL I, IsActive bit )
AspNetRoles( Id PK, Name, NormalizedName, RestaurantId FK NULL )
Permissions( Id PK, Code nvarchar(60) U, Module nvarchar(40), Description )
RolePermissions( RoleId FK, PermissionId FK, PK(RoleId,PermissionId) )
UserPermissionOverrides( UserId FK, PermissionId FK, IsGranted bit,
                         PK(UserId,PermissionId) )
RefreshTokens( Id PK, UserId FK I, TokenHash varbinary(32) U,
               ExpiresAt, RevokedAt NULL, ReplacedByTokenId NULL,
               CreatedByIp, CreatedAt )

StaffProfiles(
  Id PK, UserId FK U, RestaurantId FK I, EmployeeCode nvarchar(10),
  DisplayName nvarchar(120), Phone, PrimaryRole nvarchar(30),
  IsActive bit, HiredAt, CreatedAt, UpdatedAt,
  U(RestaurantId, EmployeeCode) )
```

### 22.2 Salle

```
TableZones( Id PK, RestaurantId FK I, Name nvarchar(80),
            DisplayOrder int, IsActive bit, U(RestaurantId, Name) )

RestaurantTables(
  Id PK, RestaurantId FK I, ZoneId FK NULL, Number nvarchar(20),
  Name nvarchar(80) NULL, Capacity int CHECK(Capacity BETWEEN 1 AND 50),
  Status nvarchar(20) CHECK(Status IN ('AVAILABLE','OCCUPIED','RESERVED',
                                       'CLEANING','OUT_OF_SERVICE')),
  IsActive bit, RowVersion rowversion, CreatedAt, UpdatedAt,
  U(RestaurantId, Number), I(RestaurantId, Status) )

TableQrCodes(
  Id PK, RestaurantId FK I, TableId FK I,
  SecureTokenHash varbinary(32) U,          -- SHA-256, jamais le clair
  TokenLookupKey nvarchar(16) I,            -- préfixe indexé, recherche O(log n)
  IsActive bit, ExpiresAt NULL, CreatedAt, RegeneratedAt NULL, CreatedBy FK,
  UF(TableId) WHERE IsActive = 1 )          -- ◄ 1 seul QR actif par table

TableSessions(
  Id PK, RestaurantId FK I, TableId FK I, SessionNumber int,
  Status nvarchar(20) CHECK(Status IN ('OPEN','ACTIVE','CLOSED','CANCELLED')),
  GuestCount int CHECK(GuestCount BETWEEN 1 AND 99),
  StartedAt, EndedAt NULL, OpenedBy FK, ClosedBy FK NULL,
  Notes nvarchar(500), RowVersion rowversion,
  UF(TableId) WHERE Status IN ('OPEN','ACTIVE'),   -- ◄ 1 session ouverte / table
  I(RestaurantId, StartedAt DESC),
  I(RestaurantId, Status, TableId) )

ServiceAssignments(
  Id PK, RestaurantId FK I, TableId FK I, TableSessionId FK I, WaiterId FK I,
  Status nvarchar(20) CHECK(Status IN ('ACTIVE','ENDED','TRANSFERRED')),
  AssignedAt, UnassignedAt NULL, AssignedBy FK, Notes nvarchar(300),
  UF(TableSessionId) WHERE Status='ACTIVE',   -- ◄ 1 serveur actif / session
  I(RestaurantId, WaiterId, AssignedAt DESC),
  I(RestaurantId, TableId, AssignedAt, UnassignedAt) )  -- ◄ requête « à 20h15 »

ServiceAssignmentHistory(
  Id PK, RestaurantId FK I, TableId FK I, TableSessionId FK I,
  PreviousWaiterId FK NULL, NewWaiterId FK NULL,
  Action nvarchar(20) CHECK(Action IN ('ASSIGNED','TRANSFERRED',
                                       'UNASSIGNED','REASSIGNED')),
  ChangedBy FK, ChangedAt, Reason nvarchar(300),
  I(RestaurantId, TableId, ChangedAt DESC),
  I(RestaurantId, ChangedAt DESC) )
```

### 22.3 Catalogue

```
Stations( Id PK, RestaurantId FK I, Code nvarchar(20), Name nvarchar(80),
          DisplayOrder int, IsActive bit, U(RestaurantId, Code) )

MenuCategories( Id PK, RestaurantId FK I, Name nvarchar(120), Description,
                ImageUrl, DisplayOrder int, IsActive bit,
                U(RestaurantId, Name), I(RestaurantId, DisplayOrder) )

Products(
  Id PK, RestaurantId FK I, CategoryId FK I, StationId FK I,
  Name nvarchar(160), Description nvarchar(1000), ImageUrl nvarchar(500),
  Price decimal(18,2) CHECK(Price >= 0), TaxRate decimal(5,4),
  PreparationMinutes int, IsAvailable bit, IsActive bit,
  DisplayOrder int, RowVersion rowversion, CreatedAt, UpdatedAt,
  I(RestaurantId, CategoryId, IsActive, IsAvailable),
  I(RestaurantId, StationId) )

ProductModifiers( Id PK, ProductId FK I, Name, IsRequired bit,
                  MinSelections int, MaxSelections int, DisplayOrder,
                  CHECK(MinSelections <= MaxSelections) )
ModifierOptions( Id PK, ProductModifierId FK I, Name,
                 PriceDelta decimal(18,2), IsDefault bit,
                 IsAvailable bit, DisplayOrder )
```

### 22.4 Commandes

```
Orders(
  Id PK, RestaurantId FK I, TableId FK I, TableSessionId FK I,
  OrderNumber nvarchar(24), WaiterId FK NULL I,
  Source nvarchar(12) CHECK(Source IN ('QR','WAITER','COUNTER')),
  Status nvarchar(20) CHECK(Status IN ('DRAFT','PENDING','CONFIRMED',
    'IN_PREPARATION','PARTIALLY_READY','READY','SERVED','CANCELLED','CLOSED')),
  Subtotal decimal(18,2), TaxAmount decimal(18,2),
  DiscountAmount decimal(18,2), ServiceChargeAmount decimal(18,2),
  Total decimal(18,2) CHECK(Total >= 0), Currency char(3),
  Notes nvarchar(500),
  CreatedAt, ConfirmedAt NULL, StartedAt NULL, ReadyAt NULL,
  ServedAt NULL, ClosedAt NULL, CancelledAt NULL,
  CreatedBy FK NULL, ServedBy FK NULL, ClosedBy FK NULL, CancelledBy FK NULL,
  CancellationReason nvarchar(300) NULL, RowVersion rowversion,
  U(RestaurantId, OrderNumber),
  I(RestaurantId, Status, CreatedAt DESC),        -- KDS, dashboards
  I(RestaurantId, WaiterId, CreatedAt DESC)
    INCLUDE (Total, Status),                      -- ◄ covering index rapports
  I(TableSessionId),
  I(RestaurantId, CreatedAt DESC) INCLUDE (Total, Status, WaiterId) )

OrderItems(
  Id PK, OrderId FK I, ProductId FK I,
  ProductNameSnapshot nvarchar(160), UnitPriceSnapshot decimal(18,2),
  TaxRateSnapshot decimal(5,4), StationId FK, StationCodeSnapshot nvarchar(20),
  Quantity int CHECK(Quantity BETWEEN 1 AND 99),
  ModifiersTotal decimal(18,2), LineSubtotal decimal(18,2),
  LineTax decimal(18,2), LineTotal decimal(18,2),
  Notes nvarchar(300), Status nvarchar(20),
  I(OrderId, StationId), I(ProductId) )

OrderItemModifiers( Id PK, OrderItemId FK I, ModifierOptionId FK,
                    ModifierNameSnapshot, OptionNameSnapshot,
                    PriceDeltaSnapshot decimal(18,2) )

OrderStatusHistory(
  Id PK, OrderId FK I, PreviousStatus nvarchar(20) NULL,
  NewStatus nvarchar(20), ChangedBy FK NULL, ChangedAt, Note nvarchar(300),
  I(OrderId, ChangedAt) )
```

### 22.5 Préparation, paiement, audit

```
PreparationTickets(
  Id PK, RestaurantId FK I, OrderId FK I, StationId FK I,
  TicketNumber nvarchar(24),
  Status nvarchar(20) CHECK(Status IN ('NEW','ACCEPTED','IN_PREPARATION',
                                       'READY','PICKED_UP','CANCELLED')),
  CreatedAt, AcceptedAt NULL, StartedAt NULL, ReadyAt NULL,
  AcceptedBy FK NULL, CompletedBy FK NULL, RowVersion rowversion,
  I(RestaurantId, StationId, Status, CreatedAt),   -- ◄ requête principale du KDS
  I(OrderId) )

PreparationTicketItems(
  Id PK, PreparationTicketId FK I, OrderItemId FK,
  ProductNameSnapshot, Quantity int, Notes nvarchar(300),
  ModifiersSummary nvarchar(500), Status nvarchar(20) )

Payments(
  Id PK, RestaurantId FK I, OrderId FK I, TableSessionId FK I,
  Amount decimal(18,2) CHECK(Amount > 0), Currency char(3),
  Method nvarchar(20) CHECK(Method IN ('CASH','MONCASH','NATCASH',
                                       'STRIPE','CARD')),
  Status nvarchar(20) CHECK(Status IN ('PENDING','COMPLETED','FAILED',
                                       'REFUNDED','CANCELLED')),
  TransactionReference nvarchar(120) NULL, ProviderPayloadJson nvarchar(max),
  PaidAt NULL, ProcessedBy FK, IdempotencyKey nvarchar(80), CreatedAt,
  U(RestaurantId, IdempotencyKey),
  I(RestaurantId, PaidAt DESC), I(OrderId) )

IdempotencyRecords(
  Id PK, RestaurantId FK I, [Key] nvarchar(80), Endpoint nvarchar(200),
  RequestHash varbinary(32), ResponseStatusCode int,
  ResponseBody nvarchar(max), CreatedAt, ExpiresAt,
  U(RestaurantId, [Key], Endpoint), I(ExpiresAt) )

AuditLogs(
  Id PK bigint IDENTITY, RestaurantId FK NULL I, UserId FK NULL,
  Action nvarchar(60), EntityName nvarchar(60), EntityId nvarchar(60),
  OldValues nvarchar(max), NewValues nvarchar(max),
  IpAddress nvarchar(45), UserAgent nvarchar(300),
  CorrelationId nvarchar(60), CreatedAt,
  I(RestaurantId, CreatedAt DESC), I(RestaurantId, EntityName, EntityId),
  I(CorrelationId) )

Notifications(
  Id PK, RestaurantId FK I, TargetType nvarchar(12), TargetId nvarchar(60),
  Type nvarchar(40), Title nvarchar(160), Body nvarchar(500),
  PayloadJson nvarchar(max), IsRead bit, ReadAt NULL, CreatedAt,
  I(RestaurantId, TargetType, TargetId, IsRead, CreatedAt DESC) )
```

### 22.6 Les cinq index qui portent le système

| Index | Sert | Pourquoi il est critique |
|---|---|---|
| `UF ServiceAssignments(TableSessionId) WHERE Status='ACTIVE'` | RG-030 | La garantie ultime « un seul serveur actif ». Une course perdue devient une violation SQL, pas une donnée corrompue |
| `UF TableSessions(TableId) WHERE Status IN('OPEN','ACTIVE')` | RG-020 | Empêche deux sessions concurrentes sur une table |
| `PreparationTickets(RestaurantId, StationId, Status, CreatedAt)` | KDS | Requête exécutée toutes les 15 s par écran, toute la journée |
| `Orders(RestaurantId, WaiterId, CreatedAt) INCLUDE(Total, Status)` | Rapports serveurs | Covering index : agrégation sans lecture de la table |
| `ServiceAssignments(RestaurantId, TableId, AssignedAt, UnassignedAt)` | « Qui à 20h15 ? » | Rend la requête d'audit instantanée |

### 22.7 Volumétrie estimée (1 restaurant, 1 an)

| Table | Lignes/an | Croissance |
|---|---|---|
| Orders | ~55 000 | 150/jour |
| OrderItems | ~200 000 | 3,5 lignes/commande |
| PreparationTickets | ~80 000 | 1,5 ticket/commande |
| TableSessions | ~20 000 | 55/jour |
| ServiceAssignmentHistory | ~30 000 | 1,5 changement/session |
| AuditLogs | ~500 000 | poste dominant |

Conséquence : **partitionner ou archiver `AuditLogs`** dès la 2ᵉ année (partition
mensuelle sur `CreatedAt`, purge configurable au-delà de la rétention légale).
Pour 100 restaurants : ~50 M lignes d'audit/an → le sujet est structurant, pas cosmétique.

---

## 23. Principaux endpoints REST

### 23.1 Authentification — `/api/v1/auth`

| Méthode | Route | Permission | Description |
|---|---|---|---|
| POST | `/login` | anonyme | Email/mdp ou code employé/PIN → tokens |
| POST | `/refresh` | anonyme + refresh | Rotation du refresh token |
| POST | `/logout` | authentifié | Révoque le refresh token |
| GET | `/me` | authentifié | Profil, rôle, permissions, restaurant |
| POST | `/forgot-password` | anonyme | Envoi du lien de réinitialisation |
| POST | `/reset-password` | anonyme + token | Réinitialisation |
| POST | `/change-password` | authentifié | Changement |

### 23.2 Public / Client (jeton invité) — `/api/v1/public`

| Méthode | Route | Description |
|---|---|---|
| GET | `/qr/{token}` | Résout le QR → restaurant, table, session, **guestToken** |
| GET | `/menu` | Menu complet disponible (jeton invité) |
| GET | `/menu/categories/{id}/products` | Produits d'une catégorie |
| POST | `/cart/price` | Recalcul serveur des totaux, sans créer de commande |
| POST | `/orders` | **Idempotency-Key** — crée la commande |
| GET | `/orders/{id}` | Suivi d'une commande de ma session |
| GET | `/session` | Ma session : table, commandes, total |
| POST | `/session/call-waiter` | Appelle le serveur |

### 23.3 Tables & sessions

| Méthode | Route | Permission |
|---|---|---|
| GET | `/api/v1/tables` | `Tables.View` |
| GET | `/api/v1/tables/{id}` | `Tables.View` |
| POST | `/api/v1/tables` | `Restaurant.Manage` |
| PUT | `/api/v1/tables/{id}` | `Restaurant.Manage` |
| PATCH | `/api/v1/tables/{id}/status` | `Tables.Assign` |
| POST | `/api/v1/tables/{id}/take` | `Tables.Assign` |
| POST | `/api/v1/tables/{id}/release` | `Tables.Assign` |
| POST | `/api/v1/tables/{id}/transfer` | `Tables.Transfer` |
| GET · POST · PUT | `/api/v1/zones` | `Tables.View` / `Restaurant.Manage` |
| GET | `/api/v1/tables/{id}/qr` | `Restaurant.Manage` |
| POST | `/api/v1/tables/{id}/qr/regenerate` | `Restaurant.Manage` |
| POST | `/api/v1/tables/{id}/qr/deactivate` | `Restaurant.Manage` |
| GET | `/api/v1/tables/{id}/qr/image` | `Restaurant.Manage` (PNG/SVG imprimable) |
| GET | `/api/v1/table-sessions` | `Tables.View` |
| GET | `/api/v1/table-sessions/{id}` | `Tables.View` |
| POST | `/api/v1/table-sessions` | `Tables.Assign` |
| PATCH | `/api/v1/table-sessions/{id}/guests` | `Tables.Assign` |
| POST | `/api/v1/table-sessions/{id}/close` | `Tables.Assign` |
| GET | `/api/v1/table-sessions/{id}/bill` | `Payments.View` |
| POST | `/api/v1/table-sessions/{id}/request-bill` | `Orders.View` |

### 23.4 Affectations de service

| Méthode | Route | Description |
|---|---|---|
| GET | `/api/v1/service-assignments` | Filtres : serveur, table, session, période, statut |
| GET | `/api/v1/service-assignments/history` | Historique complet, paginé |
| GET | `/api/v1/service-assignments/at?tableId=&at=` | **« Qui était responsable à 20h15 ? »** |
| POST | `/api/v1/service-assignments/bulk-transfer` | Transfert de fin de shift |

### 23.5 Menu & catalogue

| Méthode | Route | Permission |
|---|---|---|
| GET | `/api/v1/menu` | `Menu.View` |
| GET/POST/PUT/DELETE | `/api/v1/categories[/{id}]` | `Menu.View` / `Menu.Manage` |
| GET/POST/PUT/DELETE | `/api/v1/products[/{id}]` | `Menu.View` / `Menu.Manage` |
| PATCH | `/api/v1/products/{id}/availability` | `Menu.Manage` (rupture en 1 clic) |
| POST | `/api/v1/products/{id}/modifiers` | `Menu.Manage` |
| GET/POST/PUT | `/api/v1/stations[/{id}]` | `Menu.View` / `Restaurant.Manage` |

### 23.6 Commandes

| Méthode | Route | Permission |
|---|---|---|
| GET | `/api/v1/orders` | `Orders.View` — filtres statut, table, serveur, période |
| GET | `/api/v1/orders/{id}` | `Orders.View` |
| POST | `/api/v1/orders` | `Orders.Create` — **Idempotency-Key** |
| POST | `/api/v1/orders/{id}/confirm` | `Orders.Update` — **Idempotency-Key** |
| POST | `/api/v1/orders/{id}/items` | `Orders.Update` |
| DELETE | `/api/v1/orders/{id}/items/{itemId}` | `Orders.Update` |
| POST | `/api/v1/orders/{id}/serve` | `Orders.Serve` |
| POST | `/api/v1/orders/{id}/cancel` | `Orders.Cancel` |
| POST | `/api/v1/orders/{id}/discount` | `Payments.Discount` |
| GET | `/api/v1/orders/{id}/history` | `Orders.View` |

### 23.7 Cuisine, bar, serveur, caisse

| Méthode | Route | Permission |
|---|---|---|
| GET | `/api/v1/kitchen/tickets` | `Kitchen.View` |
| GET | `/api/v1/kitchen/snapshot` | `Kitchen.View` — **resync après reconnexion** |
| POST | `/api/v1/kitchen/tickets/{id}/accept` | `Kitchen.Manage` |
| POST | `/api/v1/kitchen/tickets/{id}/start` | `Kitchen.Manage` |
| POST | `/api/v1/kitchen/tickets/{id}/ready` | `Kitchen.Manage` |
| GET/POST | `/api/v1/bar/...` | `Bar.View` / `Bar.Manage` (symétrique) |
| GET | `/api/v1/waiter/me/tables` | `Tables.View` |
| GET | `/api/v1/waiter/me/orders` | `Orders.View` |
| GET | `/api/v1/waiter/me/summary` | `Orders.View` — mes stats du jour |
| GET | `/api/v1/cashier/pending-sessions` | `Payments.View` |
| POST | `/api/v1/payments` | `Payments.Create` — **Idempotency-Key** |
| GET | `/api/v1/payments/{id}/receipt` | `Payments.View` |
| POST | `/api/v1/payments/{id}/refund` | `Payments.Create` + MANAGER |

### 23.8 Rapports & audit

| Méthode | Route | Description |
|---|---|---|
| GET | `/api/v1/reports/sales` | CA par jour/heure/serveur/zone |
| GET | `/api/v1/reports/waiters` | **Performance serveurs** (§72 du cahier des charges) |
| GET | `/api/v1/reports/waiters/{id}` | Détail d'un serveur |
| GET | `/api/v1/reports/tables` | **Performance tables** (§73) |
| GET | `/api/v1/reports/products` | Produits les plus vendus |
| GET | `/api/v1/reports/stations` | Temps de préparation par station |
| GET | `/api/v1/reports/payments` | Journal des encaissements |
| GET | `/api/v1/reports/cancellations` | Annulations et responsables |
| GET | `/api/v1/dashboard/admin` | KPI temps réel (§41) |
| GET | `/api/v1/audit` | `Audit.View` — filtres entité, action, user, période |

Tous les endpoints de liste acceptent :
`?page=&pageSize=&sortBy=&sortDirection=&search=&from=&to=`.

---

## 24. Stratégie de concurrence

### 24.1 Cartographie des conflits

| Conflit | Fréquence | Gravité | Mécanisme |
|---|---|---|---|
| Deux serveurs prennent la même table | Élevée | Haute | Transaction + `UPDLOCK` + **index unique filtré** |
| Deux clients commandent sur la même session | Élevée | Faible | Aucun — commandes indépendantes, c'est légitime |
| Deux cuisiniers acceptent le même ticket | Moyenne | Faible | `RowVersion` sur le ticket, 1ᵉʳ gagne |
| Deux caissiers encaissent la même commande | Faible | **Critique** | `RowVersion` + `Idempotency-Key` + contrôle Σ ≤ Total |
| Produit épuisé pendant la commande | Moyenne | Moyenne | Revérification dans la transaction |
| Modification de prix pendant une commande | Faible | Moyenne | Snapshot — le conflit n'existe pas par construction |
| Clôture de session pendant une commande | Faible | Moyenne | `RowVersion` sur la session + vérification du statut |

### 24.2 Les trois mécanismes

**1. Verrouillage optimiste (`RowVersion`)** — par défaut.
Sur `Orders`, `TableSessions`, `RestaurantTables`, `PreparationTickets`, `Products`.
Un conflit renvoie `409 CONCURRENCY_CONFLICT` avec l'état frais, le client rejoue.
Coût nul en l'absence de conflit — le bon défaut quand les collisions sont rares.

**2. Contrainte unique en base** — pour les invariants vitaux.
Les index uniques filtrés de §22.6 sont **la garantie finale**. Même si le code
applicatif contient une race condition, SQL Server refusera la seconde insertion.
L'exception est traduite en `409 TABLE_ALREADY_ASSIGNED`.

> C'est le point le plus important de cette section : **on ne se fie pas au code
> applicatif pour garantir l'unicité sous concurrence.** On se fie à la base.

**3. Verrouillage pessimiste** — exceptionnel, uniquement sur la prise de table.
```sql
SELECT ... FROM RestaurantTables WITH (UPDLOCK, ROWLOCK)
WHERE Id = @tableId AND RestaurantId = @rid;
```
Transaction courte (< 50 ms), un seul verrou, jamais de verrou pendant un appel réseau.

### 24.3 Séquence de prise de table sous concurrence

```
 Serveur A                    SQL Server                    Serveur B
     │                             │                             │
     ├─ BEGIN TRAN ───────────────►│                             │
     ├─ SELECT table UPDLOCK ─────►│ (verrou acquis)             │
     │                             │◄──── BEGIN TRAN ────────────┤
     │                             │◄──── SELECT table UPDLOCK ──┤
     │                             │      ⏸ BLOQUÉ               │
     ├─ INSERT assignment ACTIVE ─►│                             │
     ├─ INSERT history ───────────►│                             │
     ├─ UPDATE table OCCUPIED ────►│                             │
     ├─ COMMIT ───────────────────►│ (verrou libéré)             │
     │                             ├──── débloqué ──────────────►│
     │                             │◄──── INSERT assignment ─────┤
     │                             │  ✗ VIOLATION UX_ACTIVE      │
     │                             ├──── erreur 2601 ───────────►│
     │                             │◄──── ROLLBACK ──────────────┤
     │                                                           │
   201 Created                                    409 TABLE_ALREADY_ASSIGNED
                                          + état actuel : « Table prise par A »
```

### 24.4 Politique de rejeu

- Aucun rejeu automatique sur les opérations d'écriture métier — le client décide, après
  avoir vu l'état frais (un rejeu aveugle sur un paiement serait dangereux).
- Rejeu automatique (3 tentatives, backoff) uniquement sur les **erreurs transitoires**
  SQL (deadlock 1205, timeout), via `EnableRetryOnFailure`.
- **Jamais** de retry automatique combiné à une transaction explicite sans stratégie
  d'exécution explicite (piège classique d'EF Core, source de doubles écritures).

---

## 25. Stratégie d'idempotence

### 25.1 Principe

```
POST + Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
   │
   ▼
┌───────────────────────────────────────────────────────────────┐
│ Chercher (RestaurantId, Key, Endpoint) dans IdempotencyRecords│
├───────────────────────────────────────────────────────────────┤
│ TROUVÉ + même RequestHash                                     │
│   → renvoyer la réponse stockée, HTTP 200 + Idempotent-Replay │
│   → AUCUNE écriture                                           │
├───────────────────────────────────────────────────────────────┤
│ TROUVÉ + RequestHash DIFFÉRENT                                │
│   → 409 IDEMPOTENCY_KEY_REUSED                                │
│   (même clé, corps différent = bug client ou attaque)         │
├───────────────────────────────────────────────────────────────┤
│ NON TROUVÉ                                                    │
│   → exécuter, puis stocker (clé, hash, statut, réponse)       │
│     DANS LA MÊME TRANSACTION que l'opération métier           │
└───────────────────────────────────────────────────────────────┘
```

**Point non négociable** : l'enregistrement d'idempotence est écrit dans la **même
transaction** que la commande ou le paiement. Sinon, un crash entre les deux laisserait
une commande créée sans trace d'idempotence — et le rejeu du client en créerait une
seconde. C'est exactement le bug que cette mécanique doit empêcher.

Le contrat de course est porté par la contrainte `U(RestaurantId, Key, Endpoint)` :
deux requêtes simultanées avec la même clé — l'une commit, l'autre reçoit une violation
d'unicité, attend brièvement et renvoie la réponse enregistrée.

### 25.2 Endpoints concernés

| Endpoint | Obligatoire | Conséquence d'un doublon |
|---|---|---|
| `POST /public/orders` | **Oui** | Double commande, double préparation, double facturation |
| `POST /orders` | **Oui** | Idem |
| `POST /orders/{id}/confirm` | **Oui** | Doubles tickets en cuisine |
| `POST /payments` | **Oui** | **Double encaissement — inacceptable** |
| `POST /payments/{id}/refund` | **Oui** | Double remboursement |
| `POST /tables/{id}/take` | Non | Protégé par l'index unique |

Une requête sans `Idempotency-Key` sur un endpoint obligatoire est rejetée
(`400 IDEMPOTENCY_KEY_REQUIRED`). La clé est un UUID v4 généré **côté client**, stable à
travers les rejeux réseau (le cas d'usage réel : un serveur appuie deux fois parce que
la 4G a lagué).

Rétention : 24 h, purge par job planifié sur l'index `ExpiresAt`.

---

## 26. Stratégie d'audit

### 26.1 Deux sources complémentaires

**Source 1 — automatique (`SaveChangesInterceptor`)**
Capture les modifications de toute entité marquée `IAuditable` : ancien état, nouvel
état, uniquement les propriétés réellement modifiées. Aucun oubli possible : c'est
transversal et invisible pour le développeur.

**Source 2 — métier (`domain events`)**
Les actions qui n'ont pas de traduction évidente en `UPDATE` : login, échec de login,
consultation d'un rapport sensible, transfert de table, annulation. Écrites explicitement
avec un vocabulaire métier (`TABLE_TRANSFERRED`) et non technique
(`UPDATE ServiceAssignments`).

Les deux écrivent dans `AuditLogs`, enrichis par le contexte HTTP (user, restaurant, IP,
user-agent, correlationId).

### 26.2 Actions auditées

```
AUTHENTIFICATION   LOGIN_SUCCESS · LOGIN_FAILED · LOGOUT · PASSWORD_CHANGED
                   PASSWORD_RESET_REQUESTED · TOKEN_REFRESHED
                   REFRESH_TOKEN_REUSE_DETECTED  ◄ alerte sécurité

COMMANDES          ORDER_CREATED · ORDER_CONFIRMED · ORDER_ITEM_ADDED
                   ORDER_ITEM_REMOVED · ORDER_STATUS_CHANGED
                   ORDER_CANCELLED · ORDER_DISCOUNTED

SALLE              TABLE_ASSIGNED · TABLE_TRANSFERRED · TABLE_RELEASED
                   SESSION_OPENED · SESSION_CLOSED · GUEST_COUNT_CHANGED
                   QR_REGENERATED · QR_DEACTIVATED

PAIEMENT           PAYMENT_CREATED · PAYMENT_COMPLETED · PAYMENT_FAILED
                   PAYMENT_REFUNDED

CATALOGUE          PRODUCT_CREATED · PRODUCT_MODIFIED · PRICE_CHANGED
                   PRODUCT_AVAILABILITY_CHANGED

ADMINISTRATION     USER_CREATED · USER_DEACTIVATED · ROLE_CHANGED
                   PERMISSION_CHANGED · SETTINGS_CHANGED

SÉCURITÉ           TENANT_VIOLATION_ATTEMPT  ◄ alerte critique
                   RATE_LIMIT_EXCEEDED · UNAUTHORIZED_ACCESS_ATTEMPT
```

### 26.3 Garanties

| Garantie | Mise en œuvre |
|---|---|
| Append-only | Aucune API d'update/delete ; permission SQL restreinte au rôle applicatif |
| Intégrité transactionnelle | L'audit est écrit dans la transaction de l'opération |
| Corrélation | `CorrelationId` relie audit, logs Serilog et réponse HTTP |
| Confidentialité | Redaction des champs sensibles (mots de passe, tokens, PAN) avant sérialisation |
| Interrogeabilité | Index par restaurant/date, par entité, par corrélation |
| Rétention | Configurable (défaut 24 mois), archivage vers stockage froid ensuite |

### 26.4 Corrélation de bout en bout

```
Client  ──X-Correlation-Id: abc123──►  API
                                         ├─► Serilog  { correlationId: abc123, ... }
                                         ├─► AuditLog { CorrelationId: 'abc123' }
                                         └─► Réponse  { traceId: 'abc123' }
```

Un incident client (« ma commande a été facturée deux fois ») devient une seule requête :
`WHERE CorrelationId = 'abc123'` restitue la trace complète.

---

## 27. Stratégie de tests

### 27.1 Pyramide

```
              ╱╲          E2E (Playwright) — 5 %
             ╱  ╲         3 parcours critiques
            ╱────╲
           ╱      ╲       INTÉGRATION (Testcontainers) — 25 %
          ╱        ╲      API + SQL Server réel, tenancy, concurrence
         ╱──────────╲
        ╱            ╲    UNITAIRES (xUnit) — 70 %
       ╱              ╲   domaine pur, sans I/O, < 5 s au total
      ╱────────────────╲
```

### 27.2 Tests unitaires (domaine)

| Zone | Cas testés |
|---|---|
| `Money` | addition, multiplication, arrondi, refus de devises mixtes |
| Calcul de commande | sous-total, modifiers, taxe, remise, total, cas limites |
| Machine à états `Order` | toutes transitions légales ✓, toutes illégales ✗ |
| `TableSession.AssignWaiter` | assignment créée + historique écrit |
| `TableSession.TransferTo` | ancienne TRANSFERRED, nouvelle ACTIVE, historique complet |
| `TableSession.Close` | refus si commandes non réglées |
| Snapshot de prix | le prix produit change → la commande ne bouge pas |
| Validation modifiers | min/max/required |
| Résolution de permissions | rôle + overrides |
| Validators FluentValidation | tous les champs, toutes les bornes |

### 27.3 Tests d'intégration (Testcontainers + SQL Server)

Chaque test : base neuve, migrations appliquées, données seedées, transaction isolée.

| Suite | Vérifie |
|---|---|
| Authentification | login, refresh, rotation, détection de rejeu, lockout |
| **Cloisonnement tenant** | pour **chaque** endpoint : le restaurant B ne voit rien de A |
| QR Code | résolution, token invalide, régénération, désactivation |
| Table session | ouverture, unicité, clôture, refus si impayé |
| Commande complète | QR → menu → panier → confirmation → tickets → statuts |
| Éclatement multi-stations | pizza + mojito ⇒ 2 tickets, contenus disjoints |
| Paiement | encaissement, clôture, idempotence, refus de dépassement |
| Rapports | valeurs agrégées exactes sur un jeu de données connu |
| Audit | chaque action sensible produit sa ligne |

### 27.4 Tests de concurrence

Exécution parallèle réelle (`Task.WhenAll`), sans mock du temps.

| Test | Attendu |
|---|---|
| 10 serveurs prennent la même table simultanément | exactement 1 succès, 9 × `409` |
| 5 paiements identiques, même `Idempotency-Key` | exactement 1 `Payment` en base |
| 2 cuisiniers acceptent le même ticket | 1 succès, 1 `409` |
| 2 clôtures simultanées de session | 1 succès, 1 `409` |
| Commande pendant un transfert | cohérence : la commande a **un** serveur, le bon |

### 27.5 Les 10 tests métier obligatoires (§61 du cahier des charges)

| # | Test | Type |
|---|---|---|
| 1 | Deux serveurs ne peuvent pas prendre la même table | Concurrence |
| 2 | Un transfert ne modifie pas l'historique des anciennes commandes | Intégration |
| 3 | Une commande après transfert appartient au nouveau serveur | Intégration |
| 4 | Une commande QR est liée à la bonne table/session | Intégration |
| 5 | Un restaurant ne voit jamais les commandes d'un autre | Sécurité |
| 6 | Un utilisateur non autorisé ne peut pas lire les rapports serveurs | Sécurité |
| 7 | Chaque transfert est enregistré dans l'historique | Intégration |
| 8 | Chaque changement de statut est historisé | Intégration |
| 9 | Deux paiements même `Idempotency-Key` ⇒ un seul paiement | Concurrence |
| 10 | Après perte SignalR, le frontend récupère l'état via l'API | E2E |

Ces dix tests sont **bloquants en CI**. Un échec = pas de merge.

### 27.6 Objectifs de couverture

| Couche | Cible | Commentaire |
|---|---|---|
| Domain | ≥ 90 % | C'est là qu'est la valeur |
| Application | ≥ 80 % | Handlers et validators |
| Infrastructure | ≥ 50 % | Couverte indirectement par l'intégration |
| API | ≥ 70 % | Via les tests d'intégration |
| Frontend | ≥ 60 % | Vitest + Testing Library sur la logique et les hooks |

La couverture est un indicateur, pas un objectif : les 10 tests métier valent plus que
10 points de couverture.

---

## 28. Stratégie Docker / DevOps

### 28.1 Services Docker Compose

```
services:
  sqlserver:   # SQL Server 2022, volume persistant, healthcheck sqlcmd
  api:         # ASP.NET Core 10, depends_on sqlserver healthy
  frontend:    # build Vite → assets statiques
  nginx:       # reverse proxy, TLS, WebSocket upgrade
  seq:         # (dev) logs structurés Serilog
volumes: [ mssql-data, nginx-certs ]
networks: [ sted-network ]
```

### 28.2 Images

| Image | Base | Stratégie |
|---|---|---|
| API | `mcr.microsoft.com/dotnet/aspnet:10.0-alpine` | Build multi-étapes, utilisateur non-root, ~110 MB |
| Frontend | `nginx:alpine` | Build Vite en étape 1, assets statiques en étape 2 |
| SQL Server | `mcr.microsoft.com/mssql/server:2022-latest` | Volume nommé, mot de passe par variable d'environnement |

### 28.3 Health checks

| Endpoint | Contrôle | Usage |
|---|---|---|
| `/health/live` | Le processus répond | Redémarrage conteneur |
| `/health/ready` | SQL Server joignable, migrations à jour | Mise en service / load balancer |
| `/health/startup` | Démarrage terminé | Délai de grâce |

### 28.4 Pipeline CI/CD

```
Push / Pull Request
   │
   ├─ 1. Restore + Build (warnings as errors)
   ├─ 2. Tests unitaires + couverture
   ├─ 3. Tests d'intégration (Testcontainers, SQL Server réel)
   ├─ 4. Lint (dotnet format --verify-no-changes, ESLint, tsc --noEmit)
   ├─ 5. Sécurité (dotnet list package --vulnerable, npm audit, secret scan)
   ├─ 6. Build frontend
   ├─ 7. Build images Docker + scan (Trivy)
   └─ 8. Deploy (staging auto sur main ; production sur tag, avec approbation)
```

Étapes 1-6 bloquantes sur PR. Aucun merge avec un test rouge.

### 28.5 Configuration & secrets

| Environnement | Source des secrets |
|---|---|
| Development | User Secrets .NET (hors du dépôt) |
| Staging / Production | Variables d'environnement injectées par l'orchestrateur / coffre |
| **Jamais** | Dans `appsettings.json`, dans le code, dans Git |

`.env.example` documente les variables sans jamais contenir de valeur réelle.
Un scan de secrets tourne en CI et bloque le merge en cas de fuite.

### 28.6 Migrations en production

- Générées en scripts SQL idempotents (`dotnet ef migrations script --idempotent`).
- Appliquées par une étape de déploiement dédiée, **jamais** par l'application au
  démarrage (deux instances qui migrent en parallèle corrompent la base).
- Toujours rétrocompatibles pendant un déploiement progressif : ajouter avant de
  supprimer, en deux versions.

---

## 29. Risques techniques

| # | Risque | P | I | Mitigation |
|---|---|:-:|:-:|---|
| R1 | **Fuite inter-tenant** — un restaurant voit les données d'un autre | M | **Critique** | 3 niveaux de défense (§16.2) + tests de cloisonnement systématiques en CI + audit des tentatives |
| R2 | **Double encaissement** | M | **Critique** | Idempotence transactionnelle + RowVersion + contrôle Σ ≤ Total + test de concurrence |
| R3 | **Deux serveurs sur une table** | Élevée | Haute | Index unique filtré (garantie SQL, pas applicative) + UPDLOCK |
| R4 | **KDS figé** (SignalR tombé) — la cuisine ne voit plus les commandes | Élevée | **Critique** | Polling de secours 15 s + indicateur de connexion + resync obligatoire + alerte sonore |
| R5 | **Réseau instable** (contexte haïtien) | Élevée | Haute | PWA, retry exponentiel, idempotence, aucune confirmation hors ligne |
| R6 | **Perte de traçabilité** au transfert | Faible | Haute | Historique écrit dans l'agrégat, structurellement inévitable + test dédié |
| R7 | **Dérive des prix** (recalcul d'anciennes commandes) | Faible | Haute | Snapshots obligatoires + test qui modifie le prix produit et vérifie l'immuabilité |
| R8 | **Requêtes N+1** sur les dashboards | Élevée | Moyenne | Projections LINQ, `Include` explicites, tests de comptage de requêtes, revue SQL |
| R9 | **Explosion de la table d'audit** | Certaine | Moyenne | Partitionnement mensuel, rétention configurable, archivage froid |
| R10 | **Token QR compromis** (photo du QR partagée) | Moyenne | Moyenne | Le QR ne donne accès qu'à sa session, expire à la clôture, rotation planifiée, rate limiting |
| R11 | **Montée en charge SignalR** au-delà d'une instance | Faible (MVP) | Moyenne | Abstraction `IRealtimeNotifier` + backplane Redis prêt à activer |
| R12 | **Adoption métier** — les serveurs contournent l'outil | **Élevée** | **Critique** | UX terrain (gros boutons, 3 taps max), formation, KPI d'usage, mode « le serveur valide » activable |
| R13 | **Deadlocks SQL** sous charge | Moyenne | Moyenne | Transactions courtes, ordre de verrouillage constant, `EnableRetryOnFailure`, monitoring |
| R14 | **Fuseau horaire / heure d'été** | Moyenne | Moyenne | Tout en UTC (`DateTimeOffset`), conversion à l'affichage via `Restaurant.Timezone` |
| R15 | **Dette de complexité** du monolithe modulaire | Moyenne | Moyenne | Tests d'architecture (NetArchTest) interdisant les dépendances croisées |

*P = probabilité, I = impact.*

**R12 est le risque le plus sous-estimé.** Techniquement, ce projet est maîtrisable.
Commercialement, un système que les serveurs contournent ne produit aucune donnée — donc
aucun rapport, donc aucune valeur. C'est pourquoi l'UX terrain n'est pas cosmétique dans
ce projet : c'est une exigence fonctionnelle.

---

## 30. Recommandations d'architecture

### 30.1 Décisions structurantes (à valider maintenant)

| # | Recommandation | Pourquoi c'est irréversible plus tard |
|---|---|---|
| 1 | **`TableSession` porte les `ServiceAssignment`** | Change la frontière transactionnelle : impossible à corriger sans réécrire tout le module salle |
| 2 | **Snapshot de prix dans `OrderItem`** | Sans lui, l'historique financier est faux dès le premier changement de prix |
| 3 | **`Order.WaiterId` figé + chaîne de session vivante** | La double matérialisation doit exister dès la 1ʳᵉ commande, sinon les données passées sont perdues |
| 4 | **`TenantId` distinct de `RestaurantId` dès le jour 1** | L'ajouter après = migration de toutes les tables métier |
| 5 | **Index uniques filtrés comme garantie finale** | Ajouter la contrainte plus tard échoue sur des données déjà corrompues |
| 6 | **`decimal(18,2)` + VO `Money`** | Un changement de type monétaire en production est une opération à haut risque |
| 7 | **Guid séquentiels** | Changer de type de PK est une migration complète |
| 8 | **UTC partout (`DateTimeOffset`)** | Des dates locales en base sont irrécupérables sans ambiguïté |

### 30.2 Recommandations d'exécution

1. **Construire le vertical slice complet en premier.** Avant tout dashboard :
   QR → menu → panier → commande → ticket cuisine → servi → payé, pour **un** restaurant,
   avec **un** produit. C'est ce qui valide l'architecture avant d'y investir 15 phases.
2. **Écrire les 10 tests métier tôt**, même rouges. Ils constituent le contrat exécutable
   du système.
3. **Seeder un jeu de données réaliste** (1 restaurant, 3 zones, 20 tables, 40 produits,
   6 employés, 200 commandes historiques) : sans données, les dashboards ne se conçoivent
   pas et les problèmes de performance restent invisibles.
4. **Tests d'architecture automatisés** (NetArchTest) : `Domain` ne référence rien,
   `Application` ne référence pas EF Core, aucun module ne référence l'interne d'un autre.
5. **Un module = un dossier = un propriétaire.** La discipline du monolithe modulaire se
   perd en trois semaines si elle n'est pas outillée.
6. **Générer les types TypeScript depuis OpenAPI.** Les DTO écrits deux fois divergent
   toujours ; ici la divergence porterait sur des montants.
7. **Ne pas généraliser prématurément.** Pas d'`IRepository<T>` générique, pas de MediatR
   si les handlers restent simples, pas d'AutoMapper sur des mappings triviaux.
   Le cahier des charges le dit lui-même (§75).

### 30.3 Ce que je recommande de NE PAS faire

| Tentation | Pourquoi c'est une erreur ici |
|---|---|
| Event sourcing sur `Order` | Complexité ×3 pour un besoin déjà couvert par `OrderStatusHistory` |
| Microservices | Détruirait l'atomicité commande/tickets/historique, pour zéro bénéfice à cette échelle |
| Base de lecture séparée (CQRS complet) | Les projections LINQ + index suffisent jusqu'à ~100 restaurants |
| GraphQL | Les besoins sont connus et stables ; REST + DTOs est plus simple à sécuriser et à auditer |
| Micro-frontends | Une seule équipe, un seul design system |
| Kubernetes au MVP | Docker Compose sur un VPS suffit pour les 50 premiers restaurants |
| `IRepository<T>` générique | Fuite d'abstraction garantie dès la première requête complexe |

---

## 31. Arborescence complète du projet

```
STED.RestaurantOS/
├── STED.RestaurantOS.sln
├── README.md · ARCHITECTURE.md · DATABASE.md · API.md · SECURITY.md · DEPLOYMENT.md
├── docker-compose.yml · docker-compose.override.yml · .env.example · .gitignore
├── .editorconfig · Directory.Build.props · global.json
│
├── src/
│   ├── STED.RestaurantOS.Domain/
│   │   ├── Common/                  Entity · AggregateRoot · IDomainEvent · ValueObject
│   │   ├── Tenancy/                 ITenantEntity · TenantId · RestaurantId
│   │   ├── Restaurants/             Restaurant · RestaurantSettings · Events
│   │   ├── Staff/                   StaffProfile · EmployeeCode · StaffRole
│   │   ├── Floor/
│   │   │   ├── TableZone.cs · RestaurantTable.cs · TableStatus.cs
│   │   │   ├── TableQrCode.cs · QrToken.cs
│   │   │   ├── TableSession.cs      ◄ AGRÉGAT (assignments + historique)
│   │   │   ├── ServiceAssignment.cs · ServiceAssignmentHistory.cs
│   │   │   └── Events/
│   │   ├── Catalog/                 Station · MenuCategory · Product · Modifiers
│   │   ├── Ordering/
│   │   │   ├── Order.cs             ◄ AGRÉGAT (items + historique de statut)
│   │   │   ├── OrderItem.cs · OrderItemModifier.cs · OrderStatusHistory.cs
│   │   │   ├── OrderStatus.cs · OrderStatusTransitions.cs
│   │   │   └── Events/
│   │   ├── Preparation/             PreparationTicket · TicketItem · TicketStatus
│   │   ├── Billing/                 Payment · PaymentMethod · PaymentStatus
│   │   ├── Auditing/                AuditLog · AuditAction
│   │   ├── Notifications/           Notification
│   │   ├── ValueObjects/            Money · TaxRate · Percentage · OrderNumber · …
│   │   └── Exceptions/              DomainException · BusinessRuleViolationException
│   │
│   ├── STED.RestaurantOS.Application/
│   │   ├── Abstractions/            ITenantContext · IUnitOfWork · IRealtimeNotifier
│   │   │                            IDateTimeProvider · ICurrentUser · IQrTokenFactory
│   │   │                            IPaymentProvider · IIdempotencyStore · repositories
│   │   ├── Behaviors/               Validation · Logging · Transaction · Audit
│   │   ├── Common/                  Result<T> · PagedRequest · PagedResult · mapping
│   │   └── Modules/                 (les 19 modules de §4)
│   │        └── <Module>/           Commands/ · Queries/ · Dtos/ · Validators/ · EventHandlers/
│   │
│   ├── STED.RestaurantOS.Infrastructure/
│   │   ├── Persistence/
│   │   │   ├── AppDbContext.cs
│   │   │   ├── Configurations/      une classe IEntityTypeConfiguration par entité
│   │   │   ├── Interceptors/        Audit · Tenant · SoftDelete · DomainEvent
│   │   │   ├── Migrations/
│   │   │   ├── Repositories/
│   │   │   └── Seed/                Permissions · rôles · démo
│   │   ├── Identity/                ApplicationUser · JwtTokenService · RefreshTokenService
│   │   │                            PermissionAuthorizationHandler · policies
│   │   ├── Tenancy/                 TenantContext · TenantResolutionMiddleware
│   │   ├── Realtime/                SignalRNotifier · GroupNameProvider
│   │   ├── Idempotency/             SqlIdempotencyStore
│   │   ├── Payments/                CashPaymentProvider · PaymentProviderResolver
│   │   ├── QrCodes/                 QrTokenFactory · QrImageGenerator
│   │   ├── Logging/                 SerilogConfiguration · enrichers
│   │   └── DependencyInjection.cs
│   │
│   ├── STED.RestaurantOS.API/
│   │   ├── Program.cs
│   │   ├── Controllers/             un contrôleur par ressource (§23)
│   │   ├── Hubs/                    RestaurantHub.cs
│   │   ├── Middlewares/             CorrelationId · ExceptionHandling · RequestLogging
│   │   ├── Filters/                 IdempotencyFilter · ApiResponseFilter
│   │   ├── Configuration/           Swagger · CORS · RateLimiting · HealthChecks · Auth
│   │   ├── appsettings*.json
│   │   └── Dockerfile
│   │
│   └── STED.RestaurantOS.Shared/
│       └── ApiResponse.cs · PagedResult.cs · ErrorCodes.cs · Guard.cs · Constants.cs
│
├── tests/
│   ├── STED.RestaurantOS.UnitTests/
│   │   ├── Domain/                  par agrégat
│   │   ├── Application/             handlers · validators
│   │   └── Architecture/            NetArchTest — règles de dépendance
│   └── STED.RestaurantOS.IntegrationTests/
│       ├── Infrastructure/          WebAppFactory · SqlServerFixture (Testcontainers)
│       ├── Auth/ · Tenancy/ · Orders/ · Tables/ · Kitchen/ · Payments/ · Reports/
│       └── Concurrency/             les tests parallèles de §27.4
│
├── frontend/sted-restaurant-os-web/
│   ├── src/                         (structure détaillée §20.2)
│   ├── public/                      manifest.webmanifest · icônes · sw.js
│   ├── index.html · vite.config.ts · tailwind.config.ts · tsconfig.json
│   ├── package.json · Dockerfile
│   └── tests/                       Vitest · Testing Library · Playwright e2e
│
├── docker/
│   ├── nginx/                       nginx.conf · sites/ · certs/
│   └── sqlserver/                   init/
│
└── .github/workflows/
    ├── backend.yml · frontend.yml · e2e.yml · security.yml · release.yml
```

---

## 32. Roadmap de développement

### 32.1 Phases et livrables

| Phase | Contenu | Effort | Dépend de | Livrable vérifiable |
|:-:|---|:-:|:-:|---|
| **1** | **Analyse architecturale** (ce document) | 1 | — | Ce document validé |
| 2 | Database & Domain — entités, VOs, events, EF configs, migrations, index | 5 | 1 | `dotnet ef database update` crée le schéma complet |
| 3 | Auth & Authorization — Identity, JWT, refresh, permissions, tenancy | 4 | 2 | Login → JWT ; cloisonnement testé |
| 4 | Restaurant & Staff | 2 | 3 | CRUD restaurant + employés |
| 5 | Tables, zones, QR Codes, sessions | 3 | 4 | QR scannable → session ouverte |
| 6 | Service assignments — prise, transfert, historique, concurrence | 3 | 5 | Test « 10 serveurs, 1 table » passe |
| 7 | Menu — catégories, produits, modifiers, stations | 3 | 4 | Menu complet exposé |
| 8 | **Order engine** — panier, confirmation, prix, idempotence | **6** | 6, 7 | Commande QR de bout en bout |
| 9 | Kitchen & Bar — tickets, KDS, SignalR | 4 | 8 | Ticket visible < 1 s en cuisine |
| 10 | Waiter — dashboard, service, transfert, clôture | 3 | 9 | Parcours serveur complet |
| 11 | Cashier — paiement, reçus, clôture | 3 | 10 | Encaissement CASH + reçu |
| 12 | Reporting & analytics | 4 | 11 | Les 10 questions du §40 répondues |
| 13 | Audit & sécurité — logs, rate limiting, exception handling | 2 | 12 | Piste d'audit complète |
| 14 | Frontend final — tous les dashboards | **8** | 13 | Toutes les interfaces livrées |
| 15 | Tests — unitaires, intégration, concurrence, sécurité | 4 | 14 | Les 10 tests métier verts |
| 16 | Docker — images, compose, nginx, health checks | 2 | 15 | `docker compose up` démarre tout |
| 17 | CI/CD | 2 | 16 | Pipeline verte sur PR |
| 18 | Optimisation — SQL, frontend, cache, pagination | 3 | 17 | Objectifs de performance atteints |
| 19 | Documentation technique complète | 2 | 18 | Les 6 documents livrés |
| 20 | Production — HTTPS, backups, monitoring | 2 | 19 | Déploiement staging validé |

*Effort en unités relatives, pas en jours — la conversion dépend de l'équipe.*

### 32.2 Chemin critique

```
Phase 2 ──► 3 ──► 5 ──► 6 ──► 8 ──► 9 ──► 10 ──► 11 ──► 12 ──► 14
 domaine    auth   QR   service  ORDER   KDS   serveur  caisse  rapports  UI
                        assign   ENGINE
                                   ▲
                        Le cœur du système. Tout en dépend.
                        Aucune phase aval ne peut démarrer avant.
```

**Phase 8 est le point de bascule du projet.** Si le moteur de commande est correct
(prix, snapshot, transaction, idempotence, résolution du serveur), tout le reste est de
la construction incrémentale. S'il est bancal, chaque phase suivante en hérite.
Je recommande d'y consacrer le temps nécessaire et d'y écrire les tests en premier.

### 32.3 Jalons de démonstration

| Jalon | Après phase | Ce qui est démontrable |
|---|:-:|---|
| **M1 — Fondations** | 4 | Login, restaurant configuré, employés créés, cloisonnement prouvé |
| **M2 — Salle vivante** | 6 | QR scanné, session ouverte, serveur assigné, transfert tracé |
| **M3 — Première commande** | 8 | Un client commande depuis son téléphone, la commande existe et est correcte |
| **M4 — Service complet** ★ | 11 | Parcours intégral : scan → commande → cuisine → servi → payé |
| **M5 — Pilotage** | 14 | Tous les dashboards, tous les rapports |
| **M6 — Production** | 20 | Déployé, testé, documenté, monitoré |

★ **M4 est le jalon commercialement décisif** : c'est la première démonstration qui vend
le produit à un restaurateur.

### 32.4 Vérification des réponses aux 10 questions (§40 du cahier des charges)

| Question | Disponible après | Source de données |
|---|:-:|---|
| Q1 — Quel serveur a servi la table 12 ? | Phase 6 | `ServiceAssignment` + `History` |
| Q2 — Quelles tables Jean a-t-il servies ? | Phase 6 | `ServiceAssignment` par `WaiterId` |
| Q3 — Combien de commandes Jean a-t-il servies ? | Phase 10 | `Order.ServedBy` |
| Q4 — Quel CA Jean a-t-il généré ? | Phase 12 | `Σ Order.Total WHERE WaiterId` |
| Q5 — Qui était responsable de la table 8 à 20h15 ? | Phase 6 | Requête temporelle §12.2 |
| Q6 — Qui a transféré la table 8 à Marie ? | Phase 6 | `ServiceAssignmentHistory.ChangedBy` |
| Q7 — Qui a marqué #1025 comme servie ? | Phase 10 | `Order.ServedBy` + `OrderStatusHistory` |
| Q8 — Qui a encaissé la commande ? | Phase 11 | `Payment.ProcessedBy` |
| Q9 — Quelle table a généré cette commande ? | Phase 8 | `Order.TableId` + `TableSessionId` |
| Q10 — Combien de temps la table est-elle restée ouverte ? | Phase 5 | `TableSession.EndedAt - StartedAt` |

**Les 10 questions sont couvertes par le modèle de données dès la Phase 2.**
Les phases ultérieures ne font qu'exposer ces données — elles n'en créent pas de
nouvelles. C'est le test de validité du modèle proposé.

---

## Points à valider avant la Phase 2

| # | Point | Proposition |
|:-:|---|---|
| 1 | `ServiceAssignment` **dans** l'agrégat `TableSession` | Recommandé — voir §5.2 |
| 2 | Multi-tenant base unique + discriminant + 3 niveaux de défense | Recommandé — voir §16 |
| 3 | `TenantId` distinct de `RestaurantId` dès maintenant | Recommandé — voir §16.4 |
| 4 | Identifiants fortement typés (`OrderId`, `TableId`…) | Recommandé — coût initial faible, forte protection |
| 5 | `Guid` séquentiels en PK + numéros lisibles séparés | Recommandé — voir §7.3 |
| 6 | Devise par défaut **HTG**, `decimal(18,2)` | À confirmer |
| 7 | Confirmation : commande client directe en cuisine, ou validation du serveur ? | Paramétrable ; défaut proposé : **commande directe** (validation serveur désactivée) |
| 8 | Paiement MVP : **CASH uniquement** | Conforme au cahier des charges |
| 9 | Périmètre exact de la Phase 2 : domaine + EF + migrations, **sans API** | Recommandé |
| 10 | **Emplacement du code** — voir la note ci-dessous | À trancher |

### Note sur l'emplacement du code

Le dépôt courant (`edouardstess/sass_school`, branche
`claude/sted-restaurant-os-platform-r9ltic`) contient déjà **SchoolFlow**, un SaaS de
gestion scolaire en Laravel 12 + Next.js 15, avec sa propre documentation, ses workflows
CI et son `docker-compose.yml`.

STED Restaurant OS est un produit différent, avec une stack différente (.NET + React).
Trois options :

| Option | Conséquence |
|---|---|
| **A — Nouveau dépôt** (recommandé) | Propre, CI indépendante, pas de collision de `docker-compose.yml` ni de workflows |
| **B — Sous-dossier `sted-restaurant-os/`** | Cohabitation possible, mais deux produits sans rapport dans un même dépôt et deux CI à cloisonner |
| **C — Remplacement de SchoolFlow** | Destructif — nécessite une confirmation explicite |

Ce document est placé pour l'instant dans `docs/sted-restaurant-os/`, sans rien modifier
de l'existant. Merci d'indiquer l'option retenue avant la Phase 2.

---

*Fin de la Phase 1. Aucun code n'a été produit. En attente de validation pour démarrer
la Phase 2 — Database & Domain.*
