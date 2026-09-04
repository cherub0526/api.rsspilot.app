<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Media;
use App\Models\Caption;
use App\Models\Summary;
use Hypervel\Database\Seeder;

/**
 * 心智圖手動驗證用的假資料。
 *
 * 心智圖的輸入是**摘要**而不是逐字稿（見 MindmapController::buildInput()），
 * 而且只吃 status = completed、user_id = null 的那一列，所以要手動驗證就得先有
 * 一份形狀正確的摘要。逐字稿一併補上，是為了讓影片頁的其他分頁也有東西可看。
 *
 * 選中的三支影片都掛在 free = true 的來源底下，任何登入帳號都查得到（見
 * Media::isAccessibleBy()），不必先把它們加進某個人的影片庫。
 *
 * 以 resource_id 對應而不是寫死 media.id：ULID 每個環境都不一樣，寫死等於只有
 * 產生它的那台機器跑得動。找不到對應影片就跳過並提示，不會建出孤兒資料。
 *
 * **不掛在 DatabaseSeeder 底下**，要跑得指名：
 *   docker compose exec hypervel php artisan db:seed --class=MindmapDemoSeeder
 *
 * 重跑是安全的：三張表都走 updateOrCreate，只會覆蓋自己寫的那幾列。
 * 摘要與心智圖的 ai_model 一律標成 self::AI_MODEL，用來跟真的推論結果區分：
 *   select * from summaries where ai_model = 'seeder/mindmap-demo';
 */
class MindmapDemoSeeder extends Seeder
{
    /**
     * 標記這批資料的來源，方便日後辨識與清除。真的推論寫進去的是 OpenRouter 的
     * model slug，不會長這樣。
     */
    private const AI_MODEL = 'seeder/mindmap-demo';

    /**
     * 逐字稿與摘要的語言。三支都是英文演講，SummaryJob 也是拿字幕的語言當摘要
     * locale（`ISO6391::normalize($caption->locale)`），這裡照著同一條規則走。
     *
     * 使用者的介面語系對不上時，Media::summaryFor() 最後一段會退回「任何一份
     * 全站共用的摘要」，所以 zh-TW 的帳號一樣讀得到。心智圖本身的輸出語言看的是
     * 使用者的 AI 語言設定，與這裡無關。
     */
    private const LOCALE = 'en';

    /**
     * @var array<int, array{
     *   resource_id: string,
     *   short_summary: string,
     *   content: string,
     *   key_points: array<int, string>,
     *   keywords: array<int, string>,
     *   segments: array<int, array{0: float, 1: float, 2: string}>
     * }>
     */
    private const FIXTURES = [
        [
            'resource_id'   => 'yt:video:RDFGkBE2O50',
            'short_summary' => 'Former NASA engineer turned YouTuber Mark Rober argues that the way we teach science kills curiosity long before it can turn into competence. He describes the "Super Mario effect" — treating failure as a level to retry rather than a verdict on ability — and explains how a $60 million commitment to hands-on monthly build boxes is meant to reach kids who have already decided they are "not a science person."',
            'content'       => "From Mars Rovers to YouTube\nMark Rober spent seven years at NASA's Jet Propulsion Laboratory working on the Curiosity rover, and describes the culture there as one where failure is budgeted for rather than punished. Every rover landing sequence is rehearsed through thousands of simulated failures, and the engineers who catch the most problems are the most valued. He contrasts that with a classroom, where a wrong answer is recorded permanently and the student learns to stop guessing.\n\nThe Super Mario Effect\nRober ran an online puzzle with hundreds of thousands of participants. Half were told that a failed attempt cost them five points; the other half were told nothing about points. The group with no penalty attempted the puzzle roughly twice as many times and succeeded far more often. His conclusion is that the framing of failure, not the difficulty of the task, decides who keeps going. In a video game nobody quits because Mario died — they just try the level again, because the focus stays on the princess rather than on the death count.\n\nThe $60 Million Experiment\nThe talk's centrepiece is CrunchLabs, a monthly build box that ships a physical toy the child assembles themselves, paired with a video explaining the physics inside it. Rober committed the profits — he frames the figure as roughly $60 million of foregone income — to giving the boxes away free to students who cannot afford them. The bet is that a child who builds something that actually works changes their self-image before they ever meet a formal physics curriculum.\n\nWhy Hands Beat Lectures\nHe argues that science education fails not because the content is too hard but because it arrives as a sequence of answers to questions nobody asked. A build box inverts the order: the child wants the thing to work, then discovers they need the concept. Rober is explicit that this is not a replacement for schools but a supplement aimed at the narrow window — roughly ages eight to fourteen — where children decide whether they are the kind of person who does science.\n\nWhat Success Looks Like\nRober closes by admitting the experiment may not be measurable for a decade, and that the honest metric is not test scores but how many kids still describe themselves as builders at eighteen. He asks the audience to treat curiosity as a resource that can be spent or protected, and to notice how often adults spend a child's curiosity to save themselves a few minutes of explanation.",
            'key_points'    => [
                'Seven years at NASA JPL taught Rober that engineering cultures budget for failure while classrooms penalise it.',
                'The "Super Mario effect": participants told failure cost them points quit far earlier than those told nothing.',
                'The no-penalty group attempted the puzzle about twice as often and succeeded at a markedly higher rate.',
                'CrunchLabs ships a monthly build box — a working toy plus the physics video that explains it.',
                'Rober commits roughly $60 million in foregone profit to giving the boxes to students who cannot pay.',
                'The target window is ages eight to fourteen, when children decide whether science is "for them".',
                'Build-first inverts the usual order: the child wants the thing to work, then needs the concept.',
                'Rober concedes the payoff is unmeasurable for a decade and rejects test scores as the metric.',
            ],
            'keywords' => ['Mark Rober', 'CrunchLabs', 'Super Mario effect', 'STEM education', 'NASA JPL', 'failure framing', 'curiosity', 'hands-on learning'],
            'segments' => [
                [0.0, 8.4, 'I spent seven years at NASA building a rover that had exactly one chance to land on Mars.'],
                [8.4, 19.2, 'And the thing nobody tells you about that job is how much of it is spent deliberately breaking things.'],
                [19.2, 31.6, 'We simulated thousands of failures, because every failure we found on Earth was one we would not find on Mars.'],
                [31.6, 44.1, 'Then I left, and I started making videos, and I noticed something that genuinely bothered me.'],
                [44.1, 58.7, 'Kids would tell me, at nine years old, that they were not a science person. Nine.'],
                [58.7, 72.3, 'So I ran an experiment. I put a puzzle online and I got about fifty thousand people to try it.'],
                [72.3, 86.9, 'Half of them were told: every failed attempt costs you five points. The other half were told nothing.'],
                [86.9, 101.4, 'Same puzzle. Same difficulty. The group with no penalty tried roughly twice as many times.'],
                [101.4, 118.0, 'And they solved it far more often. The difficulty never changed. Only the framing of failure changed.'],
                [118.0, 133.5, 'I call it the Super Mario effect. Nobody rage-quits Mario because Mario died. You just run the level again.'],
                [133.5, 149.8, 'Your attention stays on the princess. The deaths are just data on the way there.'],
                [149.8, 167.2, 'So here is what I decided to do about it, and my accountant was not thrilled.'],
                [167.2, 184.6, 'Every month we ship a box with a real toy inside that you build yourself, and a video explaining the physics.'],
                [184.6, 203.9, 'And we are giving away about sixty million dollars worth of them to kids who cannot afford one.'],
                [203.9, 221.5, 'Because a kid who builds something that actually works stops asking whether they are a science person.'],
                [221.5, 240.0, 'I will not know if this worked for about ten years. That is the honest answer.'],
                [240.0, 258.7, 'The metric is not test scores. The metric is how many of them still call themselves builders at eighteen.'],
            ],
        ],
        [
            'resource_id'   => 'yt:video:sjve3aXUl_A',
            'short_summary' => 'Political scientist Ian Bremmer, whose job requires tracking crises across dozens of countries at once, argues that information overload is a curation problem rather than a volume problem. He describes reading for structural change rather than events, deliberately maintaining sources he disagrees with, and treating most breaking news as noise that will resolve itself within a week.',
            'content'       => "The Problem Is Not Volume\nBremmer opens by rejecting the common framing that there is simply too much news. The volume of world events has not meaningfully changed; what changed is that every event now arrives with the same urgency signal attached. His working distinction is between events, which are what happened, and structure, which is what makes certain events likely. He reads almost exclusively for the second, on the grounds that anyone can catch up on an event in five minutes but nobody catches up on a decade of structural drift.\n\nA Deliberate Reading Diet\nHe describes maintaining a small number of sources he trusts on method rather than conclusion, and a deliberately uncomfortable set of sources whose conclusions he expects to disagree with. The point of the second set is not balance as a virtue but calibration: if he can no longer state the strongest version of the opposing case, he treats that as evidence his own model has gone stale. He is blunt that most people do the opposite and mistake the resulting comfort for understanding.\n\nThe One-Week Test\nBremmer's practical filter is to ask whether a story will still matter in a week. Most breaking news fails that test, and he argues that consuming it in real time buys nothing except anxiety, because the early version is usually wrong and will be corrected without any effort on the reader's part. The stories that pass the test are usually boring on the day they appear — a change in trade rules, a demographic figure, a quiet shift in who controls a supply chain.\n\nWhy Access Does Not Equal Insight\nHaving access to world leaders, he says, is far less useful than people assume. Leaders describe their intentions, and intentions are the least predictive thing about a state's behaviour. Constraints predict behaviour: geography, debt, energy dependence, who can be replaced and who cannot. He uses conversations to test whether decision-makers understand their own constraints, not to collect their forecasts.\n\nAdvice for the Overwhelmed\nHe closes with three concrete habits: cut the number of sources rather than adding more, read one long thing a week instead of thirty short ones, and write down predictions with dates so that being wrong is visible. The last one, he argues, is the only real defence against the feeling of being informed, which is much easier to acquire than actually being informed.",
            'key_points'    => [
                'Information overload is a curation failure, not a volume problem — everything now arrives with the same urgency signal.',
                'Bremmer separates events (what happened) from structure (what makes events likely) and reads mainly for structure.',
                'He keeps sources he trusts on method plus a deliberately uncomfortable set he expects to disagree with.',
                'Losing the ability to state the strongest opposing case is treated as evidence his own model has gone stale.',
                'The one-week test: if a story will not matter in a week, reading it in real time buys only anxiety.',
                'Stories that survive the test are usually boring on the day — trade rules, demographics, supply-chain control.',
                'Access to leaders is overrated: intentions are weakly predictive, constraints are strongly predictive.',
                'Three habits: cut sources rather than add them, read one long thing weekly, and date your predictions.',
            ],
            'keywords' => ['Ian Bremmer', 'information overload', 'geopolitics', 'media diet', 'structural analysis', 'forecasting', 'curation', 'news literacy'],
            'segments' => [
                [0.0, 9.7, 'People ask me how I keep up with everything, and the honest answer is that I do not try to.'],
                [9.7, 22.4, 'There is not more news than there used to be. There is more news arriving at maximum urgency.'],
                [22.4, 36.1, 'Everything gets the same red banner. A coup and a cabinet reshuffle look identical on your phone.'],
                [36.1, 50.8, 'So the first thing I do is separate events from structure. Events are what happened.'],
                [50.8, 65.2, 'Structure is what makes certain events likely. You can catch up on an event in five minutes.'],
                [65.2, 79.6, 'Nobody catches up on ten years of structural drift. That is the part you have to read continuously.'],
                [79.6, 95.3, 'The second thing is that I keep sources I expect to disagree with, and I keep them on purpose.'],
                [95.3, 111.0, 'Not for balance. For calibration. If I cannot argue the other side well, my model has gone stale.'],
                [111.0, 127.8, 'Third: I ask whether this will matter in a week. Most breaking news fails that immediately.'],
                [127.8, 144.2, 'And the early version is usually wrong anyway. It gets corrected whether you watched it or not.'],
                [144.2, 161.5, 'The stories that pass are boring on the day. A trade rule. A birth rate. Who quietly controls a port.'],
                [161.5, 179.0, 'People think my job is useful because I get in the room with these leaders. It mostly is not.'],
                [179.0, 196.4, 'Leaders tell you their intentions, and intentions are the least predictive thing about a state.'],
                [196.4, 213.7, 'Constraints predict behaviour. Geography, debt, energy, who they can afford to replace.'],
                [213.7, 231.9, 'So if you are drowning, my advice is to cut sources, not add them. Read one long thing a week.'],
                [231.9, 250.0, 'And write your predictions down with a date on them, because feeling informed is very easy.'],
            ],
        ],
        [
            'resource_id'   => 'yt:video:Gqy1E5piq1w',
            'short_summary' => 'Waymo co-CEO Tekedra Mawakana makes the case to TED\'s Sal Khan that fully driverless vehicles should be judged as a public-health intervention rather than a consumer gadget. She addresses the asymmetry in how society tolerates human versus machine error, the operational reality of scaling city by city, and why partial automation is the most dangerous design point.',
            'content'       => "Framing It as Public Health\nMawakana opens by reframing the category. Roughly 1.2 million people die in road collisions each year worldwide, and the overwhelming majority of those crashes involve human error — impairment, distraction, speed, fatigue. Her argument is that if a technology reliably removed a large share of that number, we would recognise it as a public-health intervention rather than a consumer product, and would regulate it accordingly.\n\nThe Asymmetry of Trust\nKhan presses on the obvious objection: people forgive human drivers and do not forgive machines. Mawakana accepts the asymmetry as a fact to be engineered around rather than argued away. Waymo's answer is transparency about the safety record — publishing collision data per million miles and inviting external analysis — and accepting that the bar for a driverless system is not parity with the average human driver but a visible, measurable improvement on it.\n\nWhy Partial Automation Is the Hard Case\nA central claim is that the most dangerous configuration is a system good enough that the driver stops paying attention but not good enough to be left alone. Handing control back to a disengaged human in the two seconds before an incident is, she argues, a design that cannot be made safe. This is the reasoning behind skipping the intermediate levels entirely and building for the case where there is no expectation of a human fallback.\n\nScaling Is an Operations Problem\nMawakana is candid that the remaining difficulty is not primarily the driving model. Each new city brings its own road furniture, weather, emergency-vehicle conventions, construction patterns and local regulators. Expansion therefore looks like a sequence of city-scale deployments rather than a single software release, and she describes the depot, remote assistance and maintenance operation as an equal part of the product.\n\nWho Benefits First\nAsked who the technology is actually for, she points to people already excluded from driving — older riders, people with visual impairments, those who cannot afford a car in a city with weak transit. She concedes the fares are not yet cheap enough for that to be the dominant use, and frames the cost curve as the real milestone rather than the technical demonstration.\n\nThe Honest Uncertainties\nThe conversation closes on what is unresolved: how liability settles when there is no driver, what happens to driving jobs over a longer horizon than most projections cover, and whether public tolerance survives the first high-profile failure of a system that is nonetheless statistically safer.",
            'key_points'    => [
                'Around 1.2 million people die in road collisions each year, overwhelmingly from human error.',
                'Mawakana argues driverless vehicles should be judged as a public-health intervention, not a consumer gadget.',
                'Society forgives human error and not machine error; Waymo treats that asymmetry as an engineering constraint.',
                'The response is published safety data per million miles and openness to external analysis.',
                'Partial automation is the most dangerous design point: good enough to disengage the driver, not good enough to be alone.',
                'Handing control back to a disengaged human seconds before an incident cannot be made safe.',
                'Scaling is an operations problem — road furniture, weather, emergency conventions and regulators differ per city.',
                'The intended beneficiaries are people already excluded from driving; fare cost, not technical proof, is the real milestone.',
                'Open questions: liability without a driver, long-run driving employment, and public tolerance of the first bad failure.',
            ],
            'keywords' => ['Waymo', 'autonomous vehicles', 'Tekedra Mawakana', 'road safety', 'public health', 'partial automation', 'scaling operations', 'liability'],
            'segments' => [
                [0.0, 10.6, 'About one point two million people die on roads every year, and almost all of it is human error.'],
                [10.6, 24.3, 'If a vaccine took a meaningful share off that number, we would not call it a gadget.'],
                [24.3, 38.9, 'So the framing I would like people to try is public health, not consumer technology.'],
                [38.9, 54.1, 'Sal Khan: But people forgive human drivers. They do not forgive machines. How do you deal with that?'],
                [54.1, 70.5, 'You do not argue with it. You engineer around it. That asymmetry is a real constraint.'],
                [70.5, 87.2, 'Which for us means publishing the collision data per million miles and letting other people check it.'],
                [87.2, 104.8, 'The bar is not being as good as an average driver. It has to be visibly, measurably better.'],
                [104.8, 122.4, 'The part that surprises people is that the dangerous design is the halfway one.'],
                [122.4, 140.1, 'Good enough that you stop paying attention. Not good enough to be left alone.'],
                [140.1, 158.6, 'Handing the wheel back to a disengaged human two seconds before something happens is not a safe design.'],
                [158.6, 176.9, 'So we skipped it. We built for the case where nobody is expected to take over.'],
                [176.9, 195.3, 'The hard part left is not really the driving. It is every city having its own everything.'],
                [195.3, 213.7, 'Different road markings, different weather, different fire trucks, different regulators.'],
                [213.7, 232.0, 'Sal Khan: So who is this actually for, first?'],
                [232.0, 250.8, 'People who already cannot drive. Older riders. People with low vision. People without a car.'],
                [250.8, 269.4, 'And I will be honest that the fare is not cheap enough yet for that to be the main use.'],
                [269.4, 288.0, 'The milestone I care about is the cost curve, not another demo.'],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::FIXTURES as $fixture) {
            $media = Media::query()->where('resource_id', $fixture['resource_id'])->first();

            if (!$media instanceof Media) {
                $this->command?->warn("  skip {$fixture['resource_id']}：這個環境沒有這支影片");

                continue;
            }

            $this->seedSummary($media, $fixture);
            $this->seedCaption($media, $fixture);
            $this->touchMedia($media, $fixture['segments']);

            $this->command?->info("  ok   {$fixture['resource_id']}  {$media->getAttribute('title')}");
        }
    }

    /**
     * @param array{short_summary: string, content: string, key_points: array<int, string>, keywords: array<int, string>} $fixture
     */
    private function seedSummary(Media $media, array $fixture): void
    {
        // user_id 必須是 null：Media::summaryFor() 找不到使用者專屬的那份時才會退回
        // 全站共用的，而心智圖端點要的正是這一份。
        Summary::updateOrCreate(
            [
                'media_id' => (string) $media->getKey(),
                'user_id'  => null,
                'locale'   => self::LOCALE,
            ],
            [
                'status' => Summary::STATUS_COMPLETED,
                'text'   => [
                    'short_summary' => $fixture['short_summary'],
                    'long_summary'  => [
                        'content'    => $fixture['content'],
                        'key_points' => $fixture['key_points'],
                        'keywords'   => $fixture['keywords'],
                    ],
                ],
                'ai_model' => self::AI_MODEL,
            ]
        );
    }

    /**
     * @param array{segments: array<int, array{0: float, 1: float, 2: string}>} $fixture
     */
    private function seedCaption(Media $media, array $fixture): void
    {
        $segments = array_map(
            fn (array $segment): array => [
                'start' => $segment[0],
                'end'   => $segment[1],
                'text'  => $segment[2],
            ],
            $fixture['segments']
        );

        // text 是所有 segment 用空白接起來的一整條字串，時間資訊只留在 segments——
        // 三個真的寫入端都是這個形狀，Caption::timestampedTranscript() 也依賴它。
        Caption::updateOrCreate(
            [
                'media_id' => (string) $media->getKey(),
                'locale'   => self::LOCALE,
            ],
            [
                'primary'       => true,
                'text'          => implode(' ', array_column($segments, 'text')),
                'segments'      => $segments,
                'word_segments' => [],
            ]
        );
    }

    /**
     * @param array<int, array{0: float, 1: float, 2: string}> $segments
     */
    private function touchMedia(Media $media, array $segments): void
    {
        $attributes = ['status' => Media::STATUS_SUMMARIZED];

        // 這批影片是 RSS 收進來的，duration 停在 0；補成逐字稿的長度，播放器與
        // 時間軸才有東西可以對。已經有值就不動它。
        if ((int) $media->getAttribute('duration') === 0) {
            $attributes['duration'] = (int) ceil((float) end($segments)[1]);
        }

        $media->update($attributes);
    }
}
