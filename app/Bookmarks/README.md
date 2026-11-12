# `Bookmarks/`: the sidebar's bookmarks

A person keeps up to ten pages in the sidebar's Bookmarks section. A bookmark is a route name and its parameters
(`bookmarks.route`, `bookmarks.params`), never a raw address, and only a route on the allowlist in
`BookmarkablePages` can be one.

`BookmarkablePages` maps each route to the one rule its page's controller enforces (`Authorization/PageAccess`, or
`DocsPages` for the docs), so a bookmark is shown exactly while the page would open.

`SidebarBookmarks` builds the shared `bookmarks` prop. Reading it deletes every bookmark the person can no longer
open, so a unit leaving their scope (or being deleted), a trooper leaving it, or a lost Admin page all drop out on the
next page load. It is only ever read for the person signed in as themselves: `HandleInertiaRequests` hides it during
view as, act as and the tutorial, and `BookmarkController` refuses writes then.

**May depend on:** `Authorization/`, `Repositories/`, `Models/`, `Docs/` (`deptrac.yaml`). Reached from Controller and
Middleware.
