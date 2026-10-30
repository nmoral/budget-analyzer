# Catalogue des taux employeur agrégés hors retraite et de la RAFP

Ce catalogue documente la composition des trois taux employeur datés ajoutés
par #49 et consommés par la formule de coût de #14 :

- `taux_autres_cotisations_fpe` — cotisations employeur hors retraite, versant FPE/État ;
- `taux_autres_cotisations_fph` — cotisations employeur hors retraite, versant FPH/CNRACL ;
- `taux_rafp` — retraite additionnelle de la fonction publique (part employeur).

Chaque taux est stocké en `PCT_TIB` (pourcentage du traitement indiciaire brut),
avec effet au `2026-01-01` (entrée datée unique, pas de série historique). Pour
les deux agrégats, la valeur stockée est la **somme** des lignes de cotisation
détaillées ci-dessous. Chaque clé pointe une entrée de `data/sources.json` dont
le `fileHash` vaut le sha256 du brut `data/sources/<clé>.json`.

Publication de référence archivée dans le dépôt :
`data/sources/publications/cdg67-valeurs-paie-2026.pdf` (CDG67 — « Valeurs de
paie au 1er janvier 2026 »). Source officielle en ligne : URSSAF, « Taux de
cotisations — Secteur public ».

## `taux_autres_cotisations_fpe` — FPE / État

- **Valeur stockée** : `16.07` % du TIB (effet `2026-01-01`).
- **Source** : `cotisations-fpe-2026` — URSSAF secteur public / CDG67 2026 (§3).

| Cotisation (employeur) | Taux | Source officielle |
|------------------------|------|-------------------|
| Maladie, maternité, invalidité, décès (État) | 9,70 % | URSSAF secteur public / CDG67 2026 §3 (taux État, distinct du 9,88 % CNRACL) |
| Allocations familiales | 5,25 % | URSSAF secteur public / CDG67 2026 §3 |
| Contribution solidarité autonomie (CSA) | 0,30 % | URSSAF secteur public / CDG67 2026 §3 |
| FNAL (employeurs ≥ 50 agents) | 0,50 % | URSSAF secteur public / CDG67 2026 §3 |
| Allocation temporaire d'invalidité (ATI) | 0,32 % | URSSAF secteur public / CDG67 2026 §3 |
| **Somme = valeur stockée** | **16,07 %** | |

Périmètre : cotisations employeur **hors** CAS Pensions (retraite) et **hors**
RAFP, qui font l'objet de clés dédiées (`taux_cas_civil`, `taux_rafp`).

## `taux_autres_cotisations_fph` — FPH / CNRACL

- **Valeur stockée** : `17.13` % du TIB (effet `2026-01-01`).
- **Source** : `cotisations-fph-2026` — URSSAF secteur public / CDG67 2026 (§2.1/§3.1).

| Cotisation (employeur) | Taux | Source officielle |
|------------------------|------|-------------------|
| Maladie, maternité, invalidité, décès (régime spécial CNRACL) | 9,88 % | CDG67 2026 §2.1/§3.1 |
| Allocations familiales | 5,25 % | CDG67 2026 §3.1 |
| Contribution solidarité autonomie (CSA) | 0,30 % | CDG67 2026 §3.1 |
| FNAL (employeurs ≥ 50 agents) | 0,50 % | CDG67 2026 §3.1 |
| Allocation temporaire d'invalidité (ATIACL) | 0,40 % | CDG67 2026 §3.1 |
| Fonds pour l'emploi hospitalier (FEH) | 0,80 % | Caisse des Dépôts (FEH 0,8 % depuis 2017) |
| **Somme = valeur stockée** | **17,13 %** | |

Périmètre : cotisations employeur **hors** CNRACL (retraite) et **hors** RAFP,
qui font l'objet de clés dédiées (`taux_cnracl`, `taux_rafp`).

## `taux_rafp` — retraite additionnelle de la fonction publique

- **Valeur stockée** : `5.00` % (part employeur, effet `2026-01-01`).
- **Source** : `rafp-2026` — décret n° 2004-569 (part employeur RAFP ; confirmé
  rafp.fr et fiche CDG67 2026).

La RAFP est une cotisation à taux unique (5 % employeur, 5 % agent) ; elle n'est
pas agrégée et constitue une clé à part entière. Note d'assiette (hors périmètre
#49, signalée) : l'assiette légale de la RAFP est le régime indemnitaire dans la
limite de 20 % du TIB, et non le TIB lui-même.

## Exclusion documentée : versement mobilité / transport

Le versement mobilité (ex-versement transport), de l'ordre de 2,00 % côté CNRACL,
est **exclu** de `taux_autres_cotisations_fph` (et de `taux_autres_cotisations_fpe`).
Son taux est fixé **localement** par chaque autorité organisatrice de la mobilité :
il n'existe pas de taux national unique, donc il n'est pas modélisable par une clé
nationale. Il est volontairement laissé hors des agrégats de #49.
