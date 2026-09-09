---
paths:
  - 'resources/js/pages/**'
---

# Pages

## AppLayout breadcrumbs take a plain string href, not a Wayfinder object
`BreadcrumbItem.href` (resources/js/types/index.d.ts) is typed as a plain `string`, unlike `Link`'s `href` prop which accepts a Wayfinder `RouteDefinition` object directly. Passing a bare route call (e.g. `href: index()`) into an `AppLayout` breadcrumbs array fails `tsc --noEmit` with "Type 'RouteDefinition<...>' is not assignable to type 'string | undefined'". Use `href: index().url` instead.
