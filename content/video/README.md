# Film documents

One file per film: a cartoon on YouTube and the questions about it, served at
`/video?v=<slug>`. The format is the reading one (`content/reading/README.md`) with
`kind: video`, a `youtube:` header holding the 11-character id, and no text section.

```
kind: video
slug: svinedrengen
title: Svinedrengen
youtube: mvvRh8db3HY

--- questions ---
1. Hvad sender prinsen til prinsessen?
* En rose og en nattergal
  En guldkrone og en hest
  Et brev og en ring
```

The questions are our own. The tale's text stays off the site: the film carries it.

```bash
docker-compose exec app php bin/reading-import.php --publish content/video/*.txt
```
