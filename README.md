# SMC Locations

Adds a new location to an SMC multi-location site by cloning an existing location. It copies:

- **Pages:** the location's page tree, minus landing pages and nearby-city pages.
- **Location terms:** the `location_category` term with all its fields, and the location's `page_type` term.
- **Theme Builder templates:** the location's header, footer and sub page template, pointed at the new location, plus any section templates with the city in the title.
- **Menu:** the location's menu, with links pointed at the new pages.

The city name, phone, and address are swapped everywhere. Every run is logged, so it can be undone.

Requires Elementor Pro and the SMC `[location]` system (the `location_category` taxonomy with ACF fields). Everything lives under the **Locations** menu in the wp-admin sidebar. Adding locations also works with `wp smc location` in WP-CLI; both do the same thing, and each can undo the other's clones.

## Hours, social links and map

The plugin adds a **Hours, Social & Map** box to every location's edit screen, below the existing phone and address fields:
- **Hours:** Monday through Sunday, plus an optional note.
- **Social:** Facebook, Instagram, YouTube, TikTok, Google Business Profile.
- **Map:** paste the Google Maps embed code (**Share > Embed a map > Copy HTML**).

The fields are defined in the plugin, so every site gets the same ones. Fill them in for each existing location once.

Show them anywhere with shortcodes. They work in Elementor's Shortcode widget, in a text widget, or as a dynamic tag, and show the current page's location, just like `[location]`:

| Shortcode | Shows |
|---|---|
| `[location_hours]` | Hours, formatted per **Locations > Settings** (see below) |
| `[location_hours style="table"]` | Override the layout for one spot: `lines`, `table` or `list` |
| `[location_hours group="no" days="full" show_closed="no"]` | Other one-off overrides |
| `[location_social]` | List of social links |
| `[location_url]` | Link to the location's main page (`/kenton/`), or the homepage on pages with no location. `path="services/"` links to a page under it. Use it for the Site Logo link or "Home" menu items |
| `[location_form]` | The location's embedded JotForm (or its booking form) |
| `[location_team]` | The location's doctors and team (see Team below) |
| `[location_reviews]` | The location's reviews (see Reviews below) |
| `[location_map]` | Google Map, at the height set in **Locations > Settings** |
| `[location_map height="300"]` | One map at a different height (optional; overrides the setting for that map only) |
| `[location field="hours_monday"]` | One value. Works with every field name: `hours_monday` to `hours_sunday`, `hours_note`, `facebook_url`, `instagram_url`, `youtube_url`, `tiktok_url`, `google_business_url` |

Add `location="kenton"` to any of them to show a specific location, for example on the corporate homepage or the Our Locations page.

**Social icons:** use Elementor's Social Icons widget. Add an icon for every network any location uses, and set each icon's link to a dynamic **Shortcode** tag, for example `[location field="facebook_url"]` or `[location field="instagram_url"]`. Icons with no link for the current location are removed automatically, so one widget works for every location. In the Elementor editor they're dimmed instead, so you can still edit them. Turn this off under **Locations > Settings** if needed.

**Email:** each location has an email field. `[location field="email"]` shows the address; `[location field="email_link"]` gives a `mailto:` link. For an "Email Us" button, set its **Link** to a dynamic **Shortcode** tag with `[location field="email_link"]`, and its **Text** to `[location field="email_label"]`. Each location can set its own button text on its edit screen; locations that leave it blank use the site default from **Locations > Settings** ("Email Us" unless changed). At a location with no email, the button is hidden automatically (dimmed in the Elementor editor). This works for any Button whose link comes from a location shortcode, and can be turned off under **Locations > Settings**. When cloning, email addresses are never altered by the city swap, and the new location keeps the copied email until you set its own.

**Embedded form:** each location has an **Embedded form** field for the JotForm shown on contact pages, popups and so on. Paste the form link, the form ID, or JotForm's embed code (**Publish > Embed**, iframe or script); it's saved as the form link. HIPAA (`hipaa.jotform.com`) and EU forms work too. Show it with `[location_form]` in a Shortcode widget.
- If a location's field is blank, `[location_form]` embeds its booking form instead. Add `fallback="no"` to turn that off.
- The frame resizes itself to fit the form, using JotForm's own script. The starting height, used only while loading, is set under **Locations > Settings** (600px by default), or per spot with `height="800"`.
- It uses the location of the page being viewed, so it works in popups and Theme Builder templates.

**Address:** `[location field="address"]` adjusts to where it's used:
- **On its own** (footer, contact page, a heading or icon list item): two lines, street, then city/state/zip.
- **Inside a sentence** (a paragraph, list item or heading with other text): one line, like "Located at 965 E Columbus St, Kenton, OH 43326, Infinity Dental is...".

To force one or the other, add `format="inline"` or `format="lines"`. Always use `format="inline"` where the address can't contain HTML, such as Elementor's Google Maps widget address or a link title.

**Hours format:** set it once for the whole site under **Locations > Settings**:
- **Layout:** lines (`Mon - Wed: 9AM - 5PM`), a table, or a bulleted list.
- **Group days:** back-to-back days with the same hours share one line (`Mon - Wed`). Matching ignores capitalization and spacing, so `9am - 5pm` and `9AM - 5PM` group together.
- **Day names:** full, or short names you can edit per site (`Thurs`, `Tues`).
- **Between grouped days:** a hyphen by default, or something like ` to `.
- **Closed days:** show or hide days marked `Closed`. Days left blank are always hidden.
- **Bold day names:** on or off.

The settings page shows a live preview using one of the site's locations. Asterisks and notes work as typed: enter `9AM - 2PM*` for Friday and `*Admin Only` as the hours note.

**Map height:** set it once for the whole site under **Locations > Settings**. You can set a desktop height and an optional phone height, in pixels (`450`) or screen height (`60vh`).

The output has plain classes (`smc-location-hours`, `smc-location-social`, `smc-location-map`) for styling in the site's CSS.

**Note:** these shortcodes come from this plugin, so any site that uses them needs the plugin active.

## Updates

The plugin updates from its GitHub repository, like any plugin from WordPress.org: new versions show up under **Dashboard > Updates** and on the Plugins screen, with release notes under **View details**. **Locations > Settings > Updates** shows the installed and newest versions and has these options:
- **Channel:** Stable, or Beta (pre-releases too) for staging sites.
- **Automatic updates.**

The repository is public, so sites need no token or other setup. Two optional `wp-config.php` settings exist for special cases: `SMC_LOCATIONS_GITHUB_TOKEN` (if the repository is ever made private, or if a server with many sites hits GitHub's limit of 60 checks per hour per server) and `SMC_LOCATIONS_GITHUB_REPO` (to test with a different repository).

How to publish a release is in `RELEASING.md`.

## The location system

The plugin provides everything the location shortcodes and templates rely on, so a new site needs nothing besides the plugin (plus Elementor Pro for the templates):

- **Location Categories** and **Page Types** (the taxonomies used by the Theme Builder conditions). Registered only if the site doesn't already have them, for example through CPT UI.
- **`[location field="..."]` and `[current_slug]`**, with the same behavior as SMC's original WPCode snippet. `[location]` also accepts `location="slug"`.
- **The base location fields** (city and state, address, phone, phone link, booking button text, link and classes), using the same ACF field keys existing SMC sites use, so saved details carry over. ACF is optional: without it, details are edited under **Locations > All Locations > Edit**.

**Existing sites** keep working exactly as they are. **Locations > Settings > Location system** shows what each part comes from, and what can be cleaned up:
1. **WPCode "Shortcodes" snippet:** deactivate it under **Code Snippets**. The plugin's shortcodes already take priority.
2. **CPT UI taxonomies:** optional. Delete **location_category** and **page_type** under **CPT UI > Add/Edit Taxonomies**, and the plugin registers them instead. Deleting them in CPT UI removes only the definition; the categories, their details and page assignments stay.
3. **ACF field group:** click **Use built-in fields**. The site's group is deactivated (not deleted), and the plugin's fields take over. If the group has extra fields the plugin doesn't know, the panel lists them so you can keep the group instead.

## Install

**First install on a site:** upload `smc-location-generator.zip` under **Plugins > Add New > Upload Plugin** (download it from the latest release on GitHub), then activate it. From then on it updates itself (see Updates above).

With WP-CLI:

```bash
wp plugin install smc-location-generator.zip --activate
```

**Upgrading a site that has the older `smc-location-cloner` folder:** deactivate and delete **SMC Locations**, then install `smc-location-generator.zip`. Locations, reviews, brand settings and all other data are kept; only the plugin's files are replaced.

## Manage locations

**Locations > All Locations** lists every location on the site. For each one it shows the address, phone, how many days of hours are set, whether it has a map and social links, its main page with a page count, and when it was added. Anything missing is shown in red.

**Shortcode help:** every field on a location's Edit screen, and in the category editor, shows the shortcode that displays it, for example `[location field="phone_label"]` for the text and `[location field="phone_link"]` for the tap-to-call link. Click a shortcode to copy it. Review fields show the `[review]` shortcodes for Loop Item templates the same way.

Hover over a location for these links:
- **Edit:** one screen for everything about the location: name, address, city/state, phone (the tap-to-call link updates to match), booking button, hours, social links, and the map, with a preview. For any other fields a site has, there's a link to the regular category editor.
- **View:** opens the location's main page.
- **Edit page:** opens the main page in WordPress.
- **Delete:** shows exactly what will be removed, each group with its own checkbox. You have to type the location's name to confirm. The groups are:
  - **Pages:** moved to the Trash, so they can be restored.
  - **Theme Builder templates** used only by this location: moved to the Trash. Templates shared with other locations are kept and listed, so you can edit their conditions.
  - **The location's menu:** deleted.
  - **The location details** (address, phone, hours and so on) and its page type: deleted.

  Delete refuses to remove pages if the site's homepage is one of them. It also reminds you to set up redirects and update the store locator.

## Launch a location

The top of each location's **Edit** screen has a **Launch checklist**. Green is done, red has to be fixed before publishing, amber is worth a look but doesn't block, and blue is information.

- **Details:** address, city and state, phone, booking link, hours and map (red if missing). A cloned location's phone and booking link are also red if they're still the copied location's. Email and the Google Business Profile link are amber.
- **Team and reviews:** at least one published doctor (red). Team members and reviews are amber.
- **Pages:** the main page, which pages are still drafts or pending (these are what gets published), and for cloned locations any page or template that still mentions the old city (amber, since some mentions are on purpose).
- **Header, footer and menu:** a header and a footer template that show on the location's pages. Using the site-wide header or footer instead is amber. A menu named after the location is amber if missing.
- **Before launch:** the store locator is checked automatically on sites using WP Store Locator or Agile Store Locator; otherwise it's a checkbox. The location count text and a desktop and mobile review are checkboxes. Ticking one saves it right away.

When nothing is red, **Publish location** publishes every draft or pending page in the location's page tree, plus its draft Theme Builder templates, then clears the Elementor cache. Private and scheduled pages are left alone. **Undo publish** puts them back the way they were for 14 days afterwards.

**All Locations** has a **Launch** column: **Live**, **Ready**, or how many items are left.

From SSH: `wp smc location checklist kenton` shows the checklist, and `wp smc location publish kenton` publishes (same rules; tick the manual checks in wp-admin first).

## Reviews

**Locations > Reviews** holds every review on the site. Each one has:
- The reviewer's name and the review text.
- A rating (1 to 5) and the review date.
- Its source (Google, Facebook, Yelp, Healthgrades, Zocdoc, Website or Other) and an optional link.
- One or more locations. Pick several for practice-wide reviews.

The list can be filtered by location, and **All Locations** shows each location's review count (or an **Add** link if it has none).

**Adding reviews**
- **One at a time:** Locations > Reviews > Add Review.
- **From the site's existing widgets:** **Locations > Import Reviews > Find reviews on this site** finds every Elementor Testimonial, Testimonial Carousel and Reviews widget, works out each one's location from its page or template, and imports the reviews in one click. The widgets themselves aren't changed.
- **From a spreadsheet:** upload a CSV on the same page. The format is shown there; only the review text is required.

Duplicates (same reviewer and text) are always skipped, so imports can be run again safely.

**Showing reviews**

The simple way is `[location_reviews]`, which shows reviews for the page's location, newest first, as cards with stars. Options:

| Option | Default | |
|---|---|---|
| `limit` | 6 | How many |
| `columns` | 3 | 1 to 4 (always 1 on phones) |
| `min_rating` | 1 | e.g. `5` for 5-star only |
| `order` | newest | or `random` |
| `words` | 0 | Shorten each review to this many words (0 = full) |
| `show_rating`, `show_source`, `show_date` | yes, yes, no | |
| `location` | page's location | a location slug, or `all` |

For a fully designed carousel or grid, use Elementor's **Loop Carousel** or **Loop Grid** with a Loop Item template. In the Loop Item, add the parts of the review with Elementor's dynamic tags, or with the `[review]` shortcode:

| Part | Dynamic tag | Or shortcode |
|---|---|---|
| Stars | Rating / Star Rating widget, value: **ACF Number > rating** | `[review field="stars"]` |
| Review text | Post Content widget, or **Post Excerpt** | `[review field="text"]`, shortened: `[review field="text" words="40"]` |
| Reviewer | Heading with **Post Title** | `[review field="name"]` |
| Source | **ACF Field > source** | `[review field="source"]` |
| Date | **Post Date** | `[review field="date"]` (`format="M j, Y"` for another format) |
| Link to the review | **ACF URL > review_link** | `[review field="link"]` |

`[review field="stars"]` uses gold stars; add `color="inherit"` to use the widget's text color instead. Set the widget's **Query ID** to one of these, and it shows the page's location's reviews automatically:
- `location_reviews`: newest first.
- `location_reviews_random`: random order.
- `location_reviews_5star`: 5-star only.

On Corporate pages (the main location on the site's homepage) and pages with no location, both ways show reviews from all locations, and `[location_reviews]` adds which office each review is for. Use `[review field="location"]` for that in a Loop Item, and `show_location="no"` to hide it.

New locations start with no reviews; they're never copied from another location. Deleting a location trashes the reviews that belong only to it.

## Brand

**Locations > Brand** sets the site's logo, favicon, colors and fonts on one screen. It edits Elementor's own **Site Settings**, not a separate copy, so the two always match, and every widget set to a global color or font updates site-wide.

- **Logo and favicon:** pick from the Media Library. The logo feeds Elementor's **Site Logo** widget and the theme. A header that uses a plain Image widget for its logo needs that widget switched to Site Logo (or changed by hand). Favicons should be square, at least 512 by 512 pixels.
- **Mobile logo:** optional. On phones (or phones and tablets, your choice, using Elementor's breakpoints) it replaces the logo automatically in Elementor's Site Logo widget, in any Image widget showing the site logo, and in `[brand_logo]`. The browser downloads only the logo it shows, so there's no need for two logo widgets with hide-on-mobile. `[brand_logo]` shows the logo linked to the homepage (`link="none"` for no link, `width="220"` for a maximum width).
- **Colors:** the four global colors (Primary, Secondary, Text, Accent) plus any custom colors, with color pickers. Rename them, add more, or remove custom ones. Values are hex codes; rgb/rgba also work.
- **Fonts:** the font and weight for each global font. The list comes from Elementor (Google Fonts, system fonts, custom fonts) with a live preview. Sizes, line height and spacing stay as set in Elementor.
- **Restore:** every save keeps the previous logo, favicon, colors and fonts (the last 10 saves), each with color swatches. **Restore** puts one back.

## Export / Import

**Locations > Export / Import** moves a site's location data to another site in one file: staging to live, or into a new client site built from the starter template.

**Export:** tick what to include, then click **Download export file**:
- **Locations:** every detail (address, phone, email, hours, social, map, forms, booking button).
- **Reviews:** with their locations.
- **Brand:** logo, mobile logo and favicon, with the image files included, plus the colors and fonts.
- **Settings:** hours format, heights, button text and so on.

**Import:** upload the file. Before anything changes, you see what's in it and what it will do: which locations are new and which already exist, how many reviews are new, and the brand's colors and fonts. Untick anything you don't want, then click **Import**.
- **Locations** are matched by their slug. An existing location is updated in place (or left alone if you choose), so its ID stays the same and templates and reviews pointing at it keep working.
- **Reviews** are never duplicated. A review already on the site gets the file's locations added to it.
- **Brand** replaces the logo, favicon, colors and fonts. The previous brand is kept under **Brand > Restore**. Images already imported once are reused, not uploaded again.

Pages, Theme Builder templates and menus aren't included; those move with the site itself (or come from Add Location).

From WP-CLI:

```bash
wp smc location export                                   # everything, to smc-locations-<site>-<date>.json
wp smc location export --sections=locations,reviews
wp smc location import smc-locations-staging-2026-09-28.json --dry-run
wp smc location import site.json --sections=brand --yes
wp smc location import site.json --keep-existing          # only add new locations
```

## Team

**Locations > Team** holds every doctor and team member. Each person has:
- A name, a photo (the **Photo** box on the right) and a bio.
- **Shows as** Doctor or Team member, a job title, and credentials, which are shown after the name ("Jane Lee, DDS").
- One or more locations, and an **Order** (under Page Attributes; lower numbers show first).

The list shows photos and can be filtered by location and by doctors or team. **All Locations** shows each location's team count (or **Add**).

**Showing the team**
- `[location_team type="doctors"]` for the Meet the Doctors page, and `[location_team type="team"]` for Meet the Team. Leave `type` out to show everyone.
- Options: `columns` (1 to 4, default 3), `bio="short|full|none"` (short is `words="40"`), `shape="circle"` for round photos, `limit`, and `location="kenton"` or `location="all"`.
- For a fully designed layout, use a **Loop Grid** or **Loop Carousel** with **Query ID** `location_doctors`, `location_staff` or `location_team`. In the Loop Item, use dynamic tags (Featured Image for the photo, Post Title, Post Content, ACF fields `job_title` and `credentials`) or `[team field="..."]` with `name`, `name_credentials`, `title`, `credentials`, `type`, `bio` (`words="30"`), `photo` or `photo_url`.

**Menu anchors:** every profile has an anchor, so a menu item or button can jump straight to it, for example `/kenton/meet-the-doctors/#dr-jane-lee`. By default it's the name as a slug (`dr-jane-lee`); set **Menu anchor** on the person to change it. The Team list shows each anchor (click to copy). `[location_team]` adds the anchors automatically. In a Loop Item, add Elementor's **Menu Anchor** widget at the top of the card with `[team field="anchor_id"]` as the ID (it outputs just the text, e.g. `dr-jane-lee`). Don't use `[team field="anchor"]` there: that one outputs a full tag and is only for a plain Shortcode widget. Elementor's CSS ID field (Advanced tab) doesn't run shortcodes, so it won't work there.

The page stops 120px above the profile so a sticky header doesn't cover it; change that with `offset="90"` on `[location_team]` or `[team field="anchor"]`. The Menu Anchor widget ignores that offset, so for it add `.elementor-menu-anchor { scroll-margin-top: 120px; }` to Site Settings > Custom CSS.

**Short bio:** each person has a **Short bio** for the location homepage and other doctor cards, separate from the full bio on Meet the Doctors. Use `[team field="short_bio"]` in a Loop Item (or the ACF field `short_bio`). `[location_team]` uses it by default (`bio="short"`); `bio="full"` shows the full bio. If Short bio is blank, the first 40 words of the full bio are used (`words="30"` to change that). It's included in Export / Import.

**Doctor buttons on the location homepage:** link each doctor straight to their profile on the location's Meet the Doctors page (`/springfield/meet-the-doctors/#dr-jane-lee`).
- In a Loop Item (Query ID `location_doctors`), add a Button and set its **Link** to the **Shortcode** dynamic tag with `[team field="profile_url"]`. For a plain text link, use `[team field="profile_link" text="Read Bio"]` in a Shortcode widget.
- Or without a Loop: `[location_team type="doctors" bio="none" button="Read Bio"]`. The button uses the site's Elementor button style.
- The page is found automatically: the location's page whose slug has "doctors" (or "dentists", "providers") in it, preferring one with "meet". Team members link to the page with "team" or "staff". If it picks the wrong page, add `page="our-doctors"` (the page's slug).
- On Corporate pages it uses Corporate's own doctors page if there is one, otherwise the doctor's first location's. If there's no doctors page at all, the button is hidden.

**Corporate:** on Corporate pages (the main location on the homepage) everything shows every location's doctors and team, each person once even if they work at several offices. `[location_team]` adds the offices under each person's title there (`show_location="no"` to hide, `"yes"` to show it everywhere); in a Loop Item use `[team field="locations"]`, or `link="yes"` to link each office to its location page. Don't tick Corporate on team members or reviews; it isn't offered and isn't needed. A location counts as Corporate if its name or slug is `corporate`.

Everything follows the page's location, so the Meet the Doctors and Meet the Team pages work for every location, including new clones. New locations start with no team; people are never copied from another location. Someone who works at two offices is added once, with both locations ticked. Deleting a location trashes the people who work only there. The team (with photos) is included in **Export / Import**, and **Scan** flags headings and profile boxes that still have a saved team member's name typed in.

## Scan for typed-in details

**Locations > Scan** finds location details that are typed into Elementor templates and pages instead of coming from the shortcodes:
- Phone numbers, including `tel:` links.
- Street addresses.
- Hours.
- Social profile links.
- Google Maps, both embedded iframes and Elementor's Google Maps widget.
- Booking form links.

Each result shows the template or page, where it displays (for example "Kenton" or "Whole site"), what was found, the widget it's in, and the shortcode to replace it with. Templates are listed first, since fixing one header or footer covers every page that uses it.

Settings controlled by an Elementor dynamic tag are skipped, and so is anything already using a `[location...]` shortcode. Run it again after fixing things; it's done when it says "Nothing found."

From WP-CLI: `wp smc location scan`, with `--templates-only`, `--pages-only`, or `--format=csv > typed-in.csv` for a spreadsheet.

## Add a location (wp-admin)

Go to **Locations > Add Location**. Only administrators can see the Locations menu.

1. **Copy from:** pick the existing location that's most similar to the new one.
2. **New location:** enter the city, phone, street address, city/state/zip, and booking form link. The copied location's values are swapped out automatically.
3. **Hours, social links and map:** each blank field shows the copied location's value in grey and keeps it. Paste the new office's Google Maps embed code. The copied location's map is never carried over.
4. **More options** (optional): extra replacements for text typed into the copied pages, like neighborhood names or a doctor mentioned in the page copy (`Downtown Oldtown => Downtown Springfield`). Doctor and team profiles come from **Locations > Team** and aren't copied, the URL slug, page status, pages to skip, and text that must never change.
5. **Preview.** The preview shows every page, template, menu, and location field that will be created, plus anything to check. Nothing is created yet.
6. **Create location.** It takes up to a minute. When it's done you get links to the new pages and a to-do list of what to finish by hand.

The **Previous clones** list at the bottom has an **Undo** button for each clone, which removes everything that clone created.

## Add a location (WP-CLI)

```bash
wp smc location sources                  # 1. see which locations exist
wp smc location init kenton Marion       # 2. write marion.json, based on Kenton
nano marion.json                         # 3. fill in every CHANGE_ME
wp smc location clone marion.json        # 4. review the plan, answer y
```

The `clone` command shows the full plan first: the location details, terms, templates, menu, pages, and any warnings. Nothing is created until you confirm. Add `--dry-run` to only see the plan.

New pages are created as **drafts**. Review them, then publish.

## Config

`init` writes this for you:

```json
{
  "source": "kenton",
  "city": "Marion",
  "state": "OH",
  "phone": "740-555-0199",
  "street": "1200 Main St",
  "city_state_zip": "Marion, OH 43302",
  "booking_link": "https://form.jotform.com/...",
  "map": "<iframe src=\"https://www.google.com/maps/embed?pb=...\" ...></iframe>",
  "hours": {
    "monday": "8:00 AM - 5:00 PM",
    "saturday": "Closed",
    "note": "Evening appointments on request"
  },
  "social": {
    "facebook": "https://facebook.com/...",
    "google": "https://g.page/..."
  },
  "replace": {}
}
```

You only enter the new office's details. The current values (Kenton's phone, street, city/state/zip) are read from the source location's fields and swapped automatically, in every format:
- **Phone:** `(419) 848-0722`, `419-848-0722`, `tel:` links, and so on.
- **Address:** with or without the `<br>`.

**Optional keys**

| Key | Default | What it does |
|---|---|---|
| `hours` | copied | `monday` to `sunday`, plus `note`. Days left out or blank keep the copied location's hours; `"none"` clears one |
| `email` | copied | The new office's email. Left out, the copied location's email is kept (with a warning) |
| `email_label` | copied | Email button text. Blank keeps the copied text; `"none"` falls back to the site default |
| `form` | copied | The JotForm for `[location_form]`: link, ID or embed code. Left out, the copied location's form is kept (with a warning), or the booking form is used if it has none |
| `social` | copied | `facebook`, `instagram`, `youtube`, `tiktok`, `google`. Blank keeps the copied link; `"none"` removes it |
| `map` | empty | Google Maps embed code or embed URL. Never copied from the source location |
| `replace` | `{}` | Extra `"old": "new"` swaps for location-specific text typed into pages: `"Downtown Oldtown": "Downtown Springfield"` |
| `slug` | city, slugified | URL slug for the new location |
| `status` | `draft` | Status for new pages |
| `exclude` | `["lp", "services/dentist-*"]` | Pages to skip, matched by slug or path under the location. Wildcards allowed. Setting this replaces the default list. |
| `fields` | `{}` | Override any location field directly, e.g. `{"booking_label": "Request Appointment"}` |
| `protect` | `[]` | Strings that must never be swapped (e.g. `"E Columbus St"` if the source city is also a street name) |
| `leftover_check` | `[]` | Extra strings to warn about if they survive the swap |
| `taxonomies` | `["location_category", "page_type"]` | Taxonomies whose location-specific terms get cloned |
| `yoast` | `{}` | Yoast title/description overrides by page slug (`_parent` for the location's main page). Supports `{city}` and `{state}`. Without this, Yoast data is copied with the city swapped. |

## Undo

```bash
wp smc location list                # clones that can be undone
wp smc location undo marion         # deletes the pages, templates, menu and terms it created
```

`undo` removes only what the clone created. Nothing else on the site is touched. It warns if any of those items were edited or published since the clone. If a clone fails partway, `undo` removes whatever it had already created.

## Per-site defaults

To change the defaults for one site (for example, a site whose landing pages live under `offers/`), add this to its snippets:

```php
add_filter( 'smc_location_cloner_defaults', function ( $d ) {
	$d['exclude'] = [ 'offers', 'services/dentist-*' ];
	return $d;
} );
```

## After cloning (manual)

- Swap the doctors and team, their photos, and any local landmark images.
- Add the location to the store locator on the Our Locations page.
- Update any "X Locations" text, like the homepage title.
- Create the location's JotForm, if you haven't yet, and set `booking_link`.
- Write the location's nearby-city pages. These are excluded from the clone on purpose.
- Add links from the corporate header or menu, if the site has one.
- Work through the **Launch checklist** on the location's edit screen, then click **Publish location**.

## Warnings you might see

- **`still contains "Kenton": ...`** Something with the old city survived the swap. The snippet shows where. Add it to `replace`, or fix it after cloning. Image filenames are never changed, but the image itself may be location-specific.
- **`links to excluded page ... (will 404)`** A cloned page links to a page that was skipped. Remove the link after cloning.
- **`has no location term`** `[location]` shortcodes on that page will be empty. That's usually fine for container pages.
- **`matched by name only; its display conditions were not copied`** A template has the city in its title but isn't tied to the location's terms. Check its conditions in **Templates > Theme Builder**.

## Troubleshooting

- **`Could not read ...json`:** JSON syntax error. Run `python3 -m json.tool marion.json` to find the line.
- **`Failed to get current SQL modes ... mariadb`:** only affects `wp db` commands, not this tool. Fix: `mkdir -p ~/bin && ln -s "$(which mysql)" ~/bin/mariadb && ln -s "$(which mysqldump)" ~/bin/mariadb-dump`, then add `~/bin` to `PATH`.
- **Create times out in wp-admin:** the host's PHP time limit is too low for a large location. Use the WP-CLI command for that site, then undo or manage it from either place.
- **Iframes or scripts missing from cloned pages:** the user running the clone needs the `unfiltered_html` capability. Single-site administrators have it; on multisite, only super admins do.
- **An email button opens the website instead of an email:** the link was set to `[location field="email"]` (the plain address) instead of `[location field="email_link"]`. Elementor treats a plain address as a web address. The plugin now corrects this automatically, but `email_link` is the right field for links.
- **The header or footer still shows another location:** open **Templates > Theme Builder** and re-save any template's conditions. That rebuilds Elementor's conditions cache.
