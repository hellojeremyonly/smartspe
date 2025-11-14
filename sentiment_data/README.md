SmartSPE Sentiment Lexicon Data

This folder contains the curated word lists (“lexicons”) and simple rule files used by SmartSPE’s sentiment engine.

The engine converts free-text peer comments into 1/3/5/0 scale and plugs those values into the matrix:

* 5 = positive
* 3 = neutral
* 1 = negative
* 0 = null (blank text)

_It is not an AI model or an ML system._

<hr>

How scoring works (high-level)

The answer text is normalized (lowercased, punctuation stripped, whitespace collapsed; "on time" -> "on-time").

Tokens scan against two tiers of lexicons:
* Tier-A: Domain list; full weight of 1.0
* Tier-B: Kaggle list; filtered by allow/deny lists; half weight of 0.5

Negators flip the next hit’s polarity once. Example: "not helpful" -> "helpful" counts as negative.

_Note: Tier-A should hold the tokens that must strongly influence sentiment (e.g., leader, reliable, rude). Tier-B is wide but muted._

<hr>

How scoring works (low-level)

If both positives and negatives are present:
>difference = positives − negatives.
>
>>If difference >= +2.0 -> Positive.
>
>>If difference <= −2.0 -> Negative.
>
>>else -> Neutral.

If only positives are present, score = 5. <br>
If only negatives are present, score = 1. <br>
If the text is empty, score = 0.

<hr>

An example of mixed praised and criticized text:
> 'Abel has excellent people skills making him very easy to work with. He used his technical skills in AI to contribute to the project’s AI requirements. Abel however, tended to waste too much meeting time with the Client by asking questions that could have been dealt with away from the client meetings. Abel also tended to hold off on starting to train the AI models as if he was scared to do so. Abel needs to have more faith in his skills to really help him contribute further in a team-based environment.'

Tier matches & weights:
* excellent (Tier-A positive, 1.0)
* easy to work with (Tier-A positive, 1.0)
* technical skills (Tier-B positive, 0.5) - filtered by allow/deny lists
* waste (Tier-B negative, -0.5)
* tended to hold off (Tier-B negative, -0.5)
* scared (Tier-B negative, -0.5)
* unnecessary questions (Tier-B negative, -0.5)

technical skills are not filtered by list, therefore, scores are:
> Positive = 2.0, Negative = -2.0, Difference = 0

There is no clear positive or negative sentiment, so the final score is Neutral (3).

<hr>

In addition to the main lexicons, phrases like 'easy-to-work-with' are normalized (hyphenated) and scored like tokens.

Booster, downtoner, contrast are also supported:

* Booster words such as not, no, never will increase the weight of the next hit.

* Downtoner words such as slight, somewhat will decrease the weight the next hit.

* Contrast cues such as but, however, although will downweight pre-contrast sentiment and slightly upweight post-contrast hit, then reset.
