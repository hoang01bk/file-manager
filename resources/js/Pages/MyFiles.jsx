import FileManager from '@/Components/FileManager';

export default function MyFiles({ userPosts = [], ...props }) {
    return <FileManager {...props} personal posts={userPosts} />;
}
