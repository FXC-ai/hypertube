# Scale for Project Hypertube

**Project:** [Hypertube](/PROJECTS/HYPERTUBE)

## Introduction

We ask you, for the good progress of this notation:

- To remain courteous, polite, respectful, constructive during this exchange. The bond of trust between the community 42 and you depends on it.
- To highlight to the group the possible malfunctions.
- To accept that there may sometimes be differences of interpretation on the subject's requests or the range of functionalities. Stay open-minded about the other's vision (is it right or wrong?), and write down as honestly as you can.

Good defense to all!

## Guidelines

> **REMEMBER THAT YOU MUST CORRECT ONLY WHAT IS ON THE STUDENT'S REPOSITORY.**

This is to make a `git clone` of the rendering repository and correct what is there.

### Attachments

- [Hypertube — Subject PDF](https://cdn.intra.42.fr/pdf/pdf/6788/hypertube.en.pdf)

## Preliminaries

### Preliminary instructions

An author file must be present at the rendering root.

Check with the supported students the libraries used for the project.

Libraries to go directly from a torrent to a video stream are prohibited: the use of a library such as WebTorrent, Pulsar or Peerflix is therefore a case of cheating.

> **If there is cheating, the defense stops.**

Also, if during the defense there is only one warning, error or notice in the web console, select **Crash** at the top of the scale.

An HTTP error code from **500 to 599** is also considered a crash.

## Security

The subject insisted on this point: the site must be secure. Check at least that:

- The passwords are encrypted in the database.
- The entry and upload forms have validations.
- No SQL injection is possible.

> **If one of these points is not valid, the defense stops.**

## Users

### Creation and management of user account

A user must be able to register, providing at least:

- Email address
- Username
- Profile picture
- First and last name
- Password (secure; any current French word should not be accepted, for example)

This information must be editable by the connected user.

**Evaluation:** ☐ Yes ☐ No

### User login

Registration and login must be possible via OmniAuth. Two minimum strategies are required:

- The 42 strategy
- A strategy freely chosen by the students

**Evaluation:** ☐ Yes ☐ No

The user must be able to log in with their credentials and receive an email to reset their password in case of forgetfulness.

**Evaluation:** ☐ Yes ☐ No

Disconnection must be possible from any page of the site.

**Evaluation:** ☐ Yes ☐ No

## Library

### Access

The library is only accessible to connected users.

**Evaluation:** ☐ Yes ☐ No

### Default view

By default, when no search has been performed, the library must display thumbnails of the most popular media from sources not managed by the application.

This list must be sorted according to a criterion chosen by the students.

**Evaluation:** ☐ Yes ☐ No

### Miniatures

The thumbnails must consist of:

- The name of the video
- Its production year, if available
- Its score, if available
- A cover image, if available

On the most popular media, all this information should logically be available.

Videos viewed must be clearly differentiated from unseen videos.

**Evaluation:** ☐ Yes ☐ No

### Pagination and sorting

The video list must be paginated, and the pages must be automatically loaded asynchronously.

The list must be sortable according to different criteria.

**Evaluation:** ☐ Yes ☐ No

### Research

The search engine must query at least **two separate external sources**, and limit the results to videos.

The result of a search must be displayed as thumbnails sorted by name.

Try to search for a video. You can have an example with:

```bash
curl 'http://www.randomlists.com/random-movies?qty=1' | cat | ruby -e "p gets.match (/ port '> ([^ <] *) /) [1]"
```

**Evaluation:** ☐ Yes ☐ No

## Video part

### Access

The section with the player is only accessible to connected users.

**Evaluation:** ☐ Yes ☐ No

### Information about the video

In addition to the player, the video section must present information about the video in progress.

Test with a popular video. You should have:

- Summary
- Cast
- Year of production
- Other relevant information

We must also find the list of comments left by users for the video, as well as the possibility of leaving a new comment.

**Evaluation:** ☐ Yes ☐ No

### Download a video

The launch of a video must launch the download of the video on the server via the **BitTorrent protocol**.

As soon as full uninterrupted playback is possible, the player starts to stream the video.

The download must be in the background, in a non-blocking way.

**Evaluation:** ☐ Yes ☐ No

### Conversion of videos

When the video is not natively readable by the browser, it must be converted **on the fly** so that it can be streamed.

At a minimum, the **MKV format** must be supported.

For example, this magnet must be streamable:

```text
magnet:?xt=urn:btih:79816060ea56d56f2a2148cd45705511079f9bca&dn=TPB.AFK.2013.720p.h264-SimonKlose&tr=udp%3A%2F%2Ftracker.openbittorrent.com%3A80&tr=udp%3A%2F%2Fopen.demonii.com%3A1337&tr=udp%3A%2F%2Ftracker.coppersurfer.tk%3A6969&tr=udp%3A%2F%2Fexodus.desync.com%3A6969
```

**Evaluation:** ☐ Yes ☐ No

### Saving videos

A video already downloaded (by any user) must be saved on the server and deleted if it has not been viewed for **one month**.

The launch of a video already present on the server must not trigger a new download, but stream from the copy on the server.

**Evaluation:** ☐ Yes ☐ No

### Subtitles

If English subtitles are available for the video, they must be automatically downloaded and selectable directly on the player.

Similarly, if the language of the video does not match the user's preferred language and subtitles are available, they must be automatically downloaded and selectable.

**Evaluation:** ☐ Yes ☐ No

## Good practices

### Look & feel

The project is the result of several weeks of group work. It must be a minimum success!

Check the following:

- Is the layout clear, with a header, a main section and a footer?
- Is the operation of the site intuitive?
- Is it responsive?
- Can a typical user quickly understand how to register and how to search?

Check **Yes** if the finished product seems successful and user-friendly.

**Evaluation:** ☐ Yes ☐ No

### Compatibility

The site is compatible with:

- Firefox >= 41
- Chrome >= 46

**Evaluation:** ☐ Yes ☐ No

### Mobile

The site is presentable on mobile and on small resolutions.

**Evaluation:** ☐ Yes ☐ No

## Bonus

### RESTful API

The application has a RESTful API.

**Evaluation:** ☐ Yes ☐ No

### Other bonuses

It is possible here to count up to **5 bonuses**, whose usefulness and relevance are judged at the discretion of the evaluator.

Examples proposed in the subject:

- Additional OmniAuth strategies (1 point per additional strategy)
- Management of different video resolutions
- Video streaming via the MediaStream API
- Other relevant bonuses

**Rating:** 0 (failed) to 5 (excellent).

## Ratings

Do not forget to check the flag.

- ☐ Okay
- ☐ Outstanding project
- ☐ Empty work
- ☐ Incomplete work
- ☐ No author file
- ☐ Cheat
- ☐ Crash
- ☐ Incomplete group
- ☐ Forbidden function

## Conclusion

Leave a comment on this evaluation.

---

## Legal information

- [General terms of use of the website](https://signin.intra.42.fr/legal/terms/6)
- [Privacy policy](https://signin.intra.42.fr/legal/terms/5)
- [Legal notices](https://signin.intra.42.fr/legal/terms/3)
- [Declaration on the use of cookies](https://signin.intra.42.fr/legal/terms/2)
- [Terms of use for video monitoring](https://signin.intra.42.fr/legal/terms/1)
