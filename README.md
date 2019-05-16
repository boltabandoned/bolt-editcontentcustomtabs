### Looking for a maintainer

This repo needs a maintainer, if you want to take it over open an issue.

Editcontentcustomtabs
======================

This [bolt.cm](https://bolt.cm/) extension allows you to place templatefields,
relations and taxonomies in any tab.

### Installation
1. Login to your Bolt installation
2. Go to "Extend" or "Extras > Extend"
3. Type `editcontentcustomtabs` into the input field
4. Click on the extension name
5. Click on "Browse Versions"
6. Click on "Install This Version" on the latest stable version

### Configuration
Add the group option to any templatefield, relation or taxonomy like so:

templatefields (in theme.yml):
```yaml
templatefields:
    extrafields.twig:
        section_1:
            type: text
            label: Section 1
            group: content
        section_2:
            type: html
            label: Section 2
        image:
            type: image
            group: media
```

relation (in contenttypes.yml):
```yaml
pages:
    name: Pages
    singular_name: Page
    fields:
    [...]
    relations:
        entries:
          group: mycustomtab
          multiple: true
```

taxonomy (in taxonomy.yml):
```yaml
tags:
    slug: tags
    singular_slug: tag
    behaves_like: tags
    group: mycustomtab
```
---

### License

This Bolt extension is open-sourced software licensed under the MIT license
