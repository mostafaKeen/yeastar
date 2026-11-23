import { AppIcon } from '@/components/common/app-icon';
import { Button } from '@/components/ui/button';
import { Pause, Play, ShieldCheck, Volume2, VolumeX } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface StreamAudioPlayerProps {
    streamUrl: string;
}

const RATES = [1, 1.25, 1.5, 2] as const;

function formatClock(sec: number): string {
    if (!Number.isFinite(sec) || sec < 0) {
        return '0:00';
    }
    const mins = Math.floor(sec / 60);
    const rem = Math.floor(sec % 60);
    return `${mins}:${rem.toString().padStart(2, '0')}`;
}

export function StreamAudioPlayer({ streamUrl }: StreamAudioPlayerProps) {
    const audioRef = useRef<HTMLAudioElement | null>(null);
    const [playing, setPlaying] = useState(false);
    const [muted, setMuted] = useState(false);
    const [currentTime, setCurrentTime] = useState(0);
    const [duration, setDuration] = useState(0);
    const [rateIndex, setRateIndex] = useState(0);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    useEffect(() => {
        setPlaying(false);
        setCurrentTime(0);
        setDuration(0);
        setErrorMessage(null);
    }, [streamUrl]);

    const togglePlay = async () => {
        const audio = audioRef.current;
        if (!audio) {
            return;
        }

        if (playing) {
            audio.pause();
            setPlaying(false);
            return;
        }

        try {
            setErrorMessage(null);
            await audio.play();
            setPlaying(true);
        } catch {
            setErrorMessage('Unable to stream recording from PBX at this moment.');
            setPlaying(false);
        }
    };

    const toggleMute = () => {
        const audio = audioRef.current;
        if (!audio) {
            return;
        }
        audio.muted = !muted;
        setMuted(!muted);
    };

    const cycleRate = () => {
        const nextIdx = (rateIndex + 1) % RATES.length;
        setRateIndex(nextIdx);
        if (audioRef.current) {
            audioRef.current.playbackRate = RATES[nextIdx];
        }
    };

    const handleSeek = (value: number) => {
        const audio = audioRef.current;
        if (!audio) {
            return;
        }
        audio.currentTime = value;
        setCurrentTime(value);
    };

    return (
        <div className="bg-muted/30 space-y-2.5 rounded-lg border p-3">
            <audio
                ref={audioRef}
                src={streamUrl}
                preload="none"
                onTimeUpdate={(e) => setCurrentTime(e.currentTarget.currentTime)}
                onLoadedMetadata={(e) => setDuration(e.currentTarget.duration)}
                onEnded={() => setPlaying(false)}
                onError={() => {
                    setPlaying(false);
                    setErrorMessage('Recording stream unavailable.');
                }}
            />

            <div className="flex items-center gap-2">
                <Button type="button" size="icon" variant="default" className="size-8 shrink-0" onClick={togglePlay} aria-label={playing ? 'Pause recording' : 'Play recording'}>
                    <AppIcon icon={playing ? Pause : Play} size="xs" />
                </Button>

                <input
                    type="range"
                    min={0}
                    max={duration || 0}
                    step={0.5}
                    value={currentTime}
                    onChange={(e) => handleSeek(Number(e.target.value))}
                    aria-label="Recording seek bar"
                    className="accent-foreground h-1.5 flex-1 cursor-pointer"
                />

                <span className="text-muted-foreground w-20 text-right font-mono text-[11px] tabular-nums">
                    {formatClock(currentTime)} / {formatClock(duration)}
                </span>

                <Button type="button" size="sm" variant="outline" className="h-7 px-2 font-mono text-[11px]" onClick={cycleRate}>
                    {RATES[rateIndex]}x
                </Button>

                <Button type="button" size="icon" variant="ghost" className="size-7" onClick={toggleMute} aria-label={muted ? 'Unmute' : 'Mute'}>
                    <AppIcon icon={muted ? VolumeX : Volume2} size="xs" />
                </Button>
            </div>

            {errorMessage ? <p className="text-destructive text-xs">{errorMessage}</p> : null}

            <div className="text-muted-foreground flex items-center gap-1.5 text-[11px]">
                <AppIcon icon={ShieldCheck} size="xs" />
                <span>Authorized Range-streamed proxy directly from Yeastar PBX (zero server disk storage).</span>
            </div>
        </div>
    );
}
